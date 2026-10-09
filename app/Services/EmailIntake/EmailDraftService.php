<?php

namespace App\Services\EmailIntake;

use App\Enums\EmailDraftStatus;
use App\Models\DisciplinaryCase;
use App\Models\EmailDraft;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseDrafts;
use App\Services\Intake\IntakeQuestionService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailDraftService
{
    public function dismiss(EmailDraft $draft, string $reason, User $actor): void
    {
        $this->authorize($draft, $actor);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('messages.report.dismissal_reason_required')]);
        }
        DB::transaction(function () use ($draft, $reason, $actor): void {
            $draft = EmailDraft::query()->lockForUpdate()->findOrFail($draft->id);
            abort_unless($draft->status === EmailDraftStatus::Received, 409);
            $draft->update(['status' => EmailDraftStatus::Dismissed, 'dismiss_reason' => $reason]);
            app(AuditLogger::class)->log('email_draft.dismissed', $draft, actor: $actor);
        });
    }

    public function convert(EmailDraft $draft, User $actor, array $facts): DisciplinaryCase
    {
        return $this->finish($draft, $actor, null, $facts);
    }

    public function link(EmailDraft $draft, DisciplinaryCase $case, User $actor): DisciplinaryCase
    {
        abort_unless(CaseVisibility::for($actor)->whereKey($case->id)->exists(), 404);
        Gate::forUser($actor)->authorize('update', $case);

        return $this->finish($draft, $actor, $case, []);
    }

    private function finish(EmailDraft $draft, User $actor, ?DisciplinaryCase $existing, array $facts): DisciplinaryCase
    {
        $this->authorize($draft, $actor);
        $copiedPaths = [];
        try {
            return DB::transaction(function () use ($draft, $actor, $existing, $facts, &$copiedPaths): DisciplinaryCase {
                $draft = EmailDraft::query()->lockForUpdate()->findOrFail($draft->id);
                abort_unless($draft->status === EmailDraftStatus::Received, 409);
                if ($facts['intake_check'] ?? null) {
                    app(IntakeQuestionService::class)->attach($facts['intake_check'], $draft, $facts['intake_answers'] ?? [], $actor);
                    unset($facts['intake_check'], $facts['intake_answers']);
                }
                $case = $existing ?? app(CaseDrafts::class)->create($facts, $actor);
                abort_unless((int) $case->company_id === (int) $draft->company_id, 404);
                app(IntakeQuestionService::class)->carryToCase($draft, $case);
                foreach ($draft->attachments as $attachment) {
                    app(AttachmentService::class)->copyEmailEvidence($attachment, $case, $copiedPaths);
                }
                $linked = $existing !== null;
                $draft->update(['status' => $linked ? EmailDraftStatus::Linked : EmailDraftStatus::Converted, 'case_id' => $case->id]);
                if ($linked) {
                    app(CaseEventLogger::class)->log($case, 'duplicate_report_linked', ['email_draft_id' => $draft->id], actor: $actor);
                }
                app(AuditLogger::class)->log($linked ? 'email_draft.linked' : 'email_draft.converted', $draft,
                    ['case_id' => $case->id], $actor);

                return $case;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($copiedPaths);
            throw $error;
        }
    }

    private function authorize(EmailDraft $draft, User $actor): void
    {
        abort_unless(EmailDraftVisibility::inbox($actor)->whereKey($draft->id)->exists(), 404);
    }
}
