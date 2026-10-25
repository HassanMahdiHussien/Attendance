<?php

namespace App\Services\Attendance\Ai;

use App\Enums\EmployeeWorkPattern;
use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatProposalEvent;
use App\Models\Attendance\ChatTurn;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChatProposals
{
    public const FIELDS = [
        'schedule_start', 'schedule_end', 'rest_days', 'work_pattern',
        'manager_employee_id', 'email', 'fallback_notification_email',
    ];

    public function __construct(private EmployeeHistory $history, private ChatSettings $settings, private AuditLogger $audit) {}

    public function propose(Company $company, ChatTurn $turn, User $actor, array $input): ChatProposal
    {
        $conversation = $turn->conversation()->firstOrFail();
        $this->authorize($company, $conversation, $actor);
        abort_unless((int) $turn->company_id === (int) $company->id && $this->settings->enabled($company), 403);
        abort_unless(in_array($turn->status, ['queued', 'running'], true), 409);
        $date = $this->date($input['effective_from'] ?? CarbonImmutable::now('Asia/Baghdad')->toDateString());
        $entries = $input['employees'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) < 1 || count($entries) > 50) {
            throw ValidationException::withMessages(['employees' => __('messages.chat.validation.employee_count')]);
        }
        $ids = array_map(fn (mixed $entry): int => is_array($entry) && is_int($entry['employee_id'] ?? null)
            ? $entry['employee_id'] : 0, $entries);
        if (in_array(0, $ids, true) || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['employees' => __('messages.chat.validation.employee_ids')]);
        }

        return DB::transaction(function () use ($company, $turn, $actor, $conversation, $entries, $ids, $date): ChatProposal {
            $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($employees->count() !== count($ids)) {
                throw ValidationException::withMessages(['employees' => __('messages.chat.validation.employee_ids')]);
            }
            $proposed = [];
            foreach ($entries as $entry) {
                $employee = $employees[(int) $entry['employee_id']];
                Gate::forUser($actor)->authorize('update', $employee);
                $changes = $entry['changes'] ?? null;
                if (! is_array($changes) || $changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) {
                    throw ValidationException::withMessages(['changes' => __('messages.chat.validation.fields')]);
                }
                $before = $this->history->asOf($employee, $date);
                $after = $this->validatedChanges($employee, $before, $changes);
                foreach ($after as $field => $value) {
                    if ($before[$field] === $value) {
                        throw ValidationException::withMessages([$field => __('messages.chat.validation.no_change')]);
                    }
                }
                $proposed[] = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code,
                    'before' => array_intersect_key($before, $after), 'after' => $after];
            }
            $proposal = ChatProposal::query()->create([
                'company_id' => $company->id, 'conversation_id' => $conversation->id, 'turn_id' => $turn->id,
                'kind' => 'employee_change', 'payload' => ['effective_from' => $date, 'employees' => $proposed],
                'status' => 'proposed', 'created_at' => now(),
            ]);
            ChatProposalEvent::query()->create(['company_id' => $company->id, 'proposal_id' => $proposal->id,
                'event' => 'proposed', 'actor_id' => $actor->id, 'details' => ['employee_ids' => $ids], 'created_at' => now()]);
            $this->audit->log('attendance.chat_proposal.proposed', $proposal,
                ['source' => 'chat', 'employee_ids' => $ids, 'effective_from' => $date], $actor);

            return $proposal;
        });
    }

    public function status(ChatProposal $proposal, User $actor): array
    {
        $this->authorize($proposal->company, $proposal->conversation, $actor);

        return $this->summary($proposal);
    }

    public function confirm(ChatProposal $proposal, User $actor): ChatProposal
    {
        $this->authorize($proposal->company, $proposal->conversation, $actor, true);
        abort_unless($this->settings->enabled($proposal->company), 403);

        return DB::transaction(function () use ($proposal, $actor): ChatProposal {
            $locked = ChatProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->authorize($locked->company, $locked->conversation, $actor, true);
            abort_unless($locked->status === 'proposed', 409);
            $payload = $locked->payload;
            $entries = collect($payload['employees'])->sortBy('employee_id')->values();
            $lockIds = $entries->pluck('employee_id')->merge($entries->pluck('after.manager_employee_id'))
                ->filter()->unique()->sort()->values()->all();
            $employees = Employee::query()->where('company_id', $locked->company_id)
                ->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stale = [];
            foreach ($entries as $entry) {
                $employee = $employees->get($entry['employee_id']);
                if ($employee === null) {
                    $stale[] = $entry['employee_id'];

                    continue;
                }
                Gate::forUser($actor)->authorize('update', $employee);
                // MySQL JSON reorders object keys, so compare the snapshot key by key, not by array order.
                $current = array_intersect_key($this->history->asOf($employee, $payload['effective_from']), $entry['before']);
                $before = $entry['before'];
                ksort($current);
                ksort($before);
                if ($current !== $before) {
                    $stale[] = $employee->id;
                }
            }
            if ($stale !== []) {
                return $this->decide($locked, $actor, 'refused', ['reason' => 'stale', 'employee_ids' => $stale]);
            }
            try {
                foreach ($entries as $entry) {
                    $employee = $employees[$entry['employee_id']];
                    $this->validatedChanges($employee, $this->history->asOf($employee, $payload['effective_from']), $entry['after']);
                }
            } catch (ValidationException) {
                return $this->decide($locked, $actor, 'refused', ['reason' => 'invalid_values']);
            }
            $versionIds = [];
            foreach ($entries as $entry) {
                $version = $this->history->record($employees[$entry['employee_id']], $entry['after'],
                    $payload['effective_from'], 'chat', $actor,
                    __('messages.chat.history_reason', ['id' => $locked->id], 'en'));
                if ($version !== null) {
                    $versionIds[] = $version->id;
                }
            }

            return $this->decide($locked, $actor, 'applied', ['employee_version_ids' => $versionIds]);
        });
    }

    public function cancel(ChatProposal $proposal, User $actor): ChatProposal
    {
        $this->authorize($proposal->company, $proposal->conversation, $actor, true);

        return DB::transaction(function () use ($proposal, $actor): ChatProposal {
            $locked = ChatProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->authorize($locked->company, $locked->conversation, $actor, true);
            abort_unless($locked->status === 'proposed', 409);
            foreach ($locked->payload['employees'] as $entry) {
                $employee = Employee::query()->where('company_id', $locked->company_id)->findOrFail($entry['employee_id']);
                Gate::forUser($actor)->authorize('update', $employee);
            }

            return $this->decide($locked, $actor, 'cancelled', ['reason' => 'user_cancelled']);
        });
    }

    public function summary(ChatProposal $proposal): array
    {
        return ['proposal_id' => $proposal->id, 'status' => $proposal->status,
            'effective_from' => $proposal->payload['effective_from'],
            'data' => ['employees' => $proposal->payload['employees']], 'result' => $proposal->result];
    }

    private function decide(ChatProposal $proposal, User $actor, string $status, array $result): ChatProposal
    {
        $proposal->update(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'result' => $result]);
        ChatProposalEvent::query()->create(['company_id' => $proposal->company_id, 'proposal_id' => $proposal->id,
            'event' => $status, 'actor_id' => $actor->id, 'details' => $result, 'created_at' => now()]);
        $this->audit->log('attendance.chat_proposal.'.$status, $proposal,
            ['before' => 'proposed', 'after' => $status, 'source' => 'chat',
                'employee_ids' => array_column($proposal->payload['employees'], 'employee_id'), 'result' => $result], $actor);

        return $proposal->fresh();
    }

    private function authorize(Company $company, ChatConversation $conversation, User $actor, bool $interactive = false): void
    {
        abort_unless($actor->is_active && (int) app(TenantContext::class)->companyId() === (int) $company->id
            && (int) $conversation->company_id === (int) $company->id
            && (int) $conversation->user_id === (int) $actor->id && $actor->canAccessTenant($company)
            && (! $interactive || auth()->id() === $actor->id), 403);
    }

    private function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['effective_from' => __('messages.chat.validation.date')]);
        }

        return $date->format('Y-m-d');
    }

    private function validatedChanges(Employee $employee, array $before, array $changes): array
    {
        $result = [];
        foreach ($changes as $field => $value) {
            if (! in_array($field, self::FIELDS, true)) {
                throw ValidationException::withMessages([$field => __('messages.chat.validation.fields')]);
            }
            if (in_array($field, ['schedule_start', 'schedule_end'], true)) {
                if ($value !== null && (! is_string($value)
                    || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?\z/', $value) !== 1)) {
                    throw ValidationException::withMessages([$field => __('messages.chat.validation.time')]);
                }
                $result[$field] = $value === null ? null : substr($value, 0, 5).':00';
            } elseif ($field === 'rest_days') {
                if ($value !== null && (! is_array($value) || ! array_is_list($value) || $value === []
                    || count($value) !== count(array_unique($value, SORT_REGULAR)))) {
                    throw ValidationException::withMessages([$field => __('messages.chat.validation.rest_days')]);
                }
                foreach ($value ?? [] as $day) {
                    if (! is_int($day) || $day < 1 || $day > 7) {
                        throw ValidationException::withMessages([$field => __('messages.chat.validation.rest_days')]);
                    }
                }
                if ($value !== null) {
                    sort($value);
                }
                $result[$field] = $value;
            } elseif ($field === 'work_pattern') {
                if ($value !== null && (! is_string($value) || EmployeeWorkPattern::tryFrom($value) === null)) {
                    throw ValidationException::withMessages([$field => __('messages.chat.validation.pattern')]);
                }
                $result[$field] = $value;
            } elseif ($field === 'manager_employee_id') {
                if ($value !== null && (! is_int($value) || $value === (int) $employee->id
                    || ! Employee::query()->where('company_id', $employee->company_id)->whereKey($value)
                        ->where('is_active', true)->exists())) {
                    throw ValidationException::withMessages([$field => __('messages.chat.validation.manager')]);
                }
                $result[$field] = $value;
            } else {
                if ($value !== null) {
                    Validator::make([$field => $value], [$field => ['string', 'email', 'max:255']])->validate();
                }
                $result[$field] = $value;
            }
        }
        $start = array_key_exists('schedule_start', $result) ? $result['schedule_start'] : $before['schedule_start'];
        $end = array_key_exists('schedule_end', $result) ? $result['schedule_end'] : $before['schedule_end'];
        if ((array_key_exists('schedule_start', $result) || array_key_exists('schedule_end', $result))
            && $start !== null && $end !== null && $start >= $end) {
            throw ValidationException::withMessages(['schedule_end' => __('messages.chat.validation.schedule')]);
        }

        return $result;
    }
}
