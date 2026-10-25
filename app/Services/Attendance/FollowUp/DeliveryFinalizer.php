<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationRoute;
use App\Models\Employee;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeliveryFinalizer
{
    public function __construct(private DeliveryEvents $events, private AuditLogger $audit) {}

    public function sent(NotificationMessage $message): void
    {
        DB::transaction(function () use ($message): void {
            $links = $message->cases()->orderBy('case_id')->get();
            $caseIds = $links->pluck('case_id')->unique();
            $employeeIds = FollowUpCase::query()->whereIn('id', $caseIds)->pluck('employee_id')->unique()->sort();
            Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
            $cases = FollowUpCase::query()->whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($links as $link) {
                $case = $cases->get($link->case_id);
                if ($case === null) {
                    continue;
                }
                $delivery = NotificationRoute::query()->firstOrCreate([
                    'company_id' => $message->company_id, 'case_id' => $case->id,
                    'route_key' => $link->route_key,
                ], ['sent_count' => 0]);
                if ($delivery->sent_count >= $link->cycle_number) {
                    continue;
                }
                $beforeState = $case->state;
                $delivery->update(['sent_count' => $link->cycle_number, 'last_sent_at' => now()]);
                if ($case->state === 'open' && $link->snapshot['route']['kind'] === 'manager') {
                    $case->update(['state' => 'waiting_manager']);
                }
                $this->events->record($case, $link->kind === 'first' ? 'sent' : 'reminded', [
                    'route_key' => $link->route_key, 'cycle_number' => $link->cycle_number,
                    'state' => $beforeState, 'sent_count' => $link->cycle_number - 1,
                ], ['route_key' => $link->route_key, 'cycle_number' => $link->cycle_number,
                    'state' => $case->state, 'sent_count' => $link->cycle_number]);
            }
            $message->update(['status' => 'sent', 'last_error' => null]);
            $this->audit->log('attendance.notification_message.sent', $message,
                ['uuid' => $message->uuid, 'case_count' => $links->count()]);
            $this->refreshBatch($message);
        });
    }

    private function refreshBatch(NotificationMessage $message): void
    {
        $batch = $message->batch;
        if ($batch->status === 'cancelled') {
            return;
        }
        $statuses = $batch->messages()->pluck('status');
        if ($statuses->contains(fn (string $status): bool => in_array($status, ['draft', 'queued', 'sending', 'uncertain'], true))) {
            $batch->update(['status' => 'sending']);

            return;
        }
        $batch->update(['status' => $statuses->contains('failed') ? 'partly_failed' : 'sent']);
    }
}
