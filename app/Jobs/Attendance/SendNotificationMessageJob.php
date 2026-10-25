<?php

namespace App\Jobs\Attendance;

use App\Mail\AttendanceFollowUpMail;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\DeliveryEvents;
use App\Services\Attendance\FollowUp\DeliveryFinalizer;
use App\Services\Attendance\FollowUp\NotificationBatches;
use App\Services\Attendance\FollowUp\RecipientResolver;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationMessageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(
        RecipientResolver $resolver,
        DeliveryEvents $events,
        DeliveryFinalizer $finalizer,
        NotificationBatches $batches,
        AuditLogger $audit,
    ): void {
        $companyId = NotificationMessage::withoutGlobalScopes()->whereKey($this->messageId)->value('company_id');
        if ($companyId === null) {
            return;
        }
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($resolver, $events, $finalizer, $batches, $audit): void {
            $prepared = DB::transaction(function () use ($resolver, $events, $batches, $audit): ?array {
                $links = NotificationMessage::query()->findOrFail($this->messageId)->cases()->get();
                $caseIds = $links->pluck('case_id')->unique();
                $employeeIds = FollowUpCase::query()->whereIn('id', $caseIds)->pluck('employee_id')->unique()->sort();
                Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
                $message = NotificationMessage::query()->whereKey($this->messageId)->lockForUpdate()->firstOrFail();
                if ($message->batch->status === 'cancelled') {
                    return null;
                }
                $started = $message->attempts()->where('result', 'started')->latest('id')->first();
                if ($started !== null && ! $message->attempts()->where('attempt_number', $started->attempt_number)
                    ->whereIn('result', ['sent', 'failed'])->exists()
                    && in_array($message->status, ['queued', 'sending'], true)) {
                    $message->update(['status' => 'uncertain', 'last_error' => 'uncertain_delivery']);
                    $audit->log('attendance.notification_message.uncertain', $message,
                        ['uuid' => $message->uuid, 'attempt' => $started->attempt_number]);

                    return null;
                }
                if ($message->status !== 'queued') {
                    return null;
                }
                $cases = FollowUpCase::query()->whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $entries = [];
                foreach ($links as $link) {
                    $case = $cases->get($link->case_id);
                    $snapshot = $link->snapshot;
                    $route = $case === null ? null : collect($case->routes)->firstWhere('key', $link->route_key);
                    $recipient = $case === null || $route === null ? null : $resolver->forRoute($case, $route);
                    if ($case === null || $case->state === 'closed' || $route !== $snapshot['route']
                        || $recipient === null || $recipient['address'] !== $snapshot['address']
                        || $recipient['address'] !== $message->address
                        || $recipient['recipient_employee_id'] !== $snapshot['recipient_employee_id']) {
                        if ($case !== null) {
                            $events->record($case, 'skipped_stale', [
                                'route_key' => $link->route_key, 'cycle_number' => $link->cycle_number,
                                'state' => 'reserved',
                            ], ['route_key' => $link->route_key, 'cycle_number' => $link->cycle_number,
                                'state' => 'released']);
                        }
                        $link->delete();

                        continue;
                    }
                    $entries[] = $snapshot;
                }
                if ($entries === []) {
                    $message->update(['status' => 'skipped']);
                    $batches->refreshStatus($message->batch);

                    return null;
                }
                $number = $message->attempts + 1;
                NotificationAttempt::create([
                    'company_id' => $message->company_id, 'message_id' => $message->id,
                    'attempt_number' => $number, 'result' => 'started',
                    'address' => $message->address, 'started_at' => now(), 'created_at' => now(),
                ]);
                $message->update(['status' => 'sending', 'attempts' => $number, 'last_error' => null]);

                return ['number' => $number, 'entries' => $entries, 'address' => $message->address,
                    'language' => $message->language, 'uuid' => $message->uuid];
            });
            if ($prepared === null) {
                return;
            }
            try {
                Mail::to($prepared['address'])->send(new AttendanceFollowUpMail(
                    $prepared['entries'], $prepared['language'], $prepared['uuid']));
            } catch (Throwable) {
                DB::transaction(function () use ($prepared, $batches, $audit): void {
                    $message = NotificationMessage::query()->whereKey($this->messageId)->lockForUpdate()->firstOrFail();
                    NotificationAttempt::create([
                        'company_id' => $message->company_id, 'message_id' => $message->id,
                        'attempt_number' => $prepared['number'], 'result' => 'failed',
                        'address' => $prepared['address'], 'started_at' => $message->attempts()
                            ->where('attempt_number', $prepared['number'])->where('result', 'started')->value('started_at'),
                        'finished_at' => now(), 'error_code' => 'mail_delivery_failed',
                    ]);
                    $message->update(['status' => $prepared['number'] < 3 ? 'queued' : 'failed',
                        'last_error' => 'mail_delivery_failed']);
                    $audit->log('attendance.notification_message.failed', $message,
                        ['uuid' => $message->uuid, 'attempt' => $prepared['number']]);
                    if ($prepared['number'] < 3) {
                        self::dispatch($message->id)->delay(now()->addMinutes([1 => 1, 2 => 5][$prepared['number']]))->afterCommit();
                    } else {
                        $batches->refreshStatus($message->batch);
                    }
                });

                return;
            }
            DB::transaction(function () use ($prepared, $finalizer): void {
                $message = NotificationMessage::query()->findOrFail($this->messageId);
                $finalizer->sent($message);
                NotificationAttempt::create([
                    'company_id' => $message->company_id, 'message_id' => $message->id,
                    'attempt_number' => $prepared['number'], 'result' => 'sent',
                    'address' => $prepared['address'], 'started_at' => $message->attempts()
                        ->where('attempt_number', $prepared['number'])->where('result', 'started')->value('started_at'),
                    'finished_at' => now(),
                ]);
            });
        });
    }
}
