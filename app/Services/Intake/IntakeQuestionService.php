<?php

namespace App\Services\Intake;

use App\Ai\IntakeQuestions;
use App\Enums\UserRole;
use App\Jobs\SuggestIntakeQuestions;
use App\Models\DisciplinaryCase;
use App\Models\EmailDraft;
use App\Models\IntakeCheck;
use App\Models\IntakeQuestion;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntakeQuestionService
{
    public function start(User $actor, array $fields): IntakeCheck
    {
        abort_unless($actor->is_active && $actor->company?->is_active, 403);
        $input = $this->input($fields);
        if (trim($input['description']) === '') {
            throw ValidationException::withMessages(['description' => __('messages.intake.description_required')]);
        }
        $check = DB::transaction(function () use ($actor, $input): IntakeCheck {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (IntakeCheck::query()->where('user_id', $actor->id)->where('created_at', '>=', now()->subMinutes(10))->count() >= 5) {
                throw ValidationException::withMessages(['description' => __('messages.intake.rate_limited')]);
            }
            $local = blank(config('services.anthropic.key'));
            $language = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'en';
            $check = IntakeCheck::create(['company_id' => $actor->company_id, 'user_id' => $actor->id,
                'status' => $local ? 'checklist' : 'pending', 'language' => $language,
                'input_hash' => $this->hash($input), 'request_token' => (string) Str::uuid(), 'input' => $local ? [] : $input,
                'result' => $local ? app(IntakeQuestions::class)->checklist($language) : null]);
            app(AuditLogger::class)->log('intake.check_requested', $check, ['mode' => $check->status], $actor);

            return $check;
        });
        if ($check->status === 'pending') {
            SuggestIntakeQuestions::dispatch($check->id, $check->company_id, $check->request_token)->afterCommit();
        }

        return $check;
    }

    public function ownedCheck(int $id, User $actor, array $fields): IntakeCheck
    {
        $check = IntakeCheck::query()->where('company_id', $actor->company_id)->where('user_id', $actor->id)->findOrFail($id);
        abort_unless(hash_equals($check->input_hash, $this->hash($this->input($fields))), 409);

        return $check;
    }

    public function attach(IntakeCheck $check, Model $parent, array $answers, User $actor): void
    {
        abort_unless((int) $check->user_id === (int) $actor->id && (int) $check->company_id === (int) $actor->company_id, 403);
        $this->authorizeParent($parent, $actor);
        abort_unless(in_array($check->status, ['ready', 'checklist'], true), 409);
        $parentKey = match (true) {
            $parent instanceof ViolationReport => 'violation_report_id',
            $parent instanceof EmailDraft => 'email_draft_id',
            $parent instanceof DisciplinaryCase => 'case_id',
        };
        $rows = $check->result['questions'] ?? [];
        DB::transaction(function () use ($check, $parent, $parentKey, $rows, $answers, $actor): void {
            IntakeQuestion::query()->where($parentKey, $parent->id)->whereNull('answer')->delete();
            foreach ($rows as $index => $row) {
                $answer = trim((string) ($answers[$index] ?? ''));
                if (mb_strlen($answer) > 5000) {
                    throw ValidationException::withMessages(['intakeAnswers.'.$index => __('messages.intake.answer_too_long')]);
                }
                $question = IntakeQuestion::create(['company_id' => $parent->company_id, $parentKey => $parent->id,
                    'intake_check_id' => $check->id, 'source' => $check->status === 'checklist' ? 'checklist' : 'ai',
                    'aspect' => $row['aspect'], 'question' => $row['question'], 'answer' => $answer === '' ? null : $answer,
                    'answered_by_user_id' => $answer === '' ? null : $actor->id,
                    'answered_at' => $answer === '' ? null : now(),
                    'sort_order' => $index]);
                if ($answer !== '') {
                    $this->logAnswer($question, $parent, $actor);
                }
            }
        });
    }

    public function answer(IntakeQuestion $question, string $answer, User $actor): void
    {
        $parent = $question->disciplinaryCase ?? $question->report ?? $question->emailDraft;
        abort_unless($parent !== null, 404);
        abort_unless(in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
        $this->authorizeParent($parent, $actor);
        if ($parent instanceof DisciplinaryCase) {
            abort_unless($parent->defense_letter_issued_at === null, 403);
        }
        abort_unless($question->answer === null, 409);
        $answer = trim($answer);
        if ($answer === '' || mb_strlen($answer) > 5000) {
            throw ValidationException::withMessages(['answer' => __('messages.intake.answer_required')]);
        }
        DB::transaction(function () use ($question, $parent, $answer, $actor): void {
            $locked = IntakeQuestion::query()->lockForUpdate()->findOrFail($question->id);
            abort_unless($locked->answer === null, 409);
            $locked->update(['answer' => $answer, 'answered_by_user_id' => $actor->id, 'answered_at' => now()]);
            $this->logAnswer($locked, $parent, $actor);
        });
    }

    public function carryToCase(ViolationReport|EmailDraft $source, DisciplinaryCase $case): void
    {
        abort_unless((int) $source->company_id === (int) $case->company_id, 404);
        $key = $source instanceof ViolationReport ? 'violation_report_id' : 'email_draft_id';
        foreach (IntakeQuestion::query()->where($key, $source->id)->orderBy('id')->get() as $row) {
            $exists = IntakeQuestion::query()->where('case_id', $case->id)->where('intake_check_id', $row->intake_check_id)
                ->where('question', $row->question)->exists();
            if (! $exists) {
                IntakeQuestion::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                    'intake_check_id' => $row->intake_check_id, 'source' => $row->source, 'aspect' => $row->aspect,
                    'question' => $row->question, 'answer' => $row->answer,
                    'answered_by_user_id' => $row->answered_by_user_id, 'answered_at' => $row->answered_at,
                    'sort_order' => $row->sort_order]);
            }
        }
    }

    private function authorizeParent(Model $parent, User $actor): void
    {
        abort_unless((int) $parent->company_id === (int) $actor->company_id, 404);
        if ($parent instanceof ViolationReport) {
            abort_unless(($parent->reporter_user_id === $actor->id && $parent->status->value === 'received')
                || ViolationReportVisibility::inbox($actor)->whereKey($parent->id)->exists(), 403);
        } elseif ($parent instanceof EmailDraft) {
            abort_unless(EmailDraftVisibility::inbox($actor)->whereKey($parent->id)->exists(), 403);
        } else {
            abort_unless(CaseVisibility::for($actor)->whereKey($parent->id)->exists(), 403);
            Gate::forUser($actor)->authorize('update', $parent);
        }
    }

    private function logAnswer(IntakeQuestion $question, Model $parent, User $actor): void
    {
        if ($parent instanceof DisciplinaryCase) {
            app(CaseEventLogger::class)->log($parent, 'intake_answer_saved', ['question_id' => $question->id], actor: $actor);
        } else {
            app(AuditLogger::class)->log('intake.answer_saved', $question, ['question_id' => $question->id], $actor);
        }
    }

    private function input(array $fields): array
    {
        $input = ['description' => trim((string) ($fields['description'] ?? '')),
            'violation_type' => trim((string) ($fields['violation_type'] ?? '')),
            'place' => trim((string) ($fields['place'] ?? '')),
            'time' => trim((string) ($fields['time'] ?? ''))];
        if ($input['time'] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $input['time'])) {
            $input['time'] = '';
        }
        if (mb_strlen($input['description']) > 5000 || mb_strlen($input['violation_type']) > 255
            || mb_strlen($input['place']) > 255 || mb_strlen($input['time']) > 40) {
            throw ValidationException::withMessages(['description' => __('messages.intake.description_too_long')]);
        }

        return $input;
    }

    private function hash(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
    }
}
