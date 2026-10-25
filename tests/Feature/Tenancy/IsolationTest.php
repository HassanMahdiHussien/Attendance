<?php

use App\Exceptions\TenantMismatch;
use App\Models\Attendance\ApproverMapping;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatMessage;
use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatProposalEvent;
use App\Models\Attendance\ChatSetting;
use App\Models\Attendance\ChatToolCall;
use App\Models\Attendance\ChatTurn;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Attendance\ImportBatch as AttendanceImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportMapping as AttendanceImportMapping;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Meal;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Attendance\NotificationRoute;
use App\Models\Attendance\NotificationSetting;
use App\Models\Attendance\Person;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportMapping;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation as LeaveAllocation;
use App\Models\Leave\Balance as LeaveBalance;
use App\Models\Leave\BalanceBatch as LeaveBalanceBatch;
use App\Models\Leave\BalanceBatchEvent as LeaveBalanceBatchEvent;
use App\Models\Leave\BalanceMapping as LeaveBalanceMapping;
use App\Models\Leave\BalanceRow as LeaveBalanceRow;
use App\Models\Leave\Credit as LeaveCredit;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Models\Leave\ImportBatchEvent as LeaveImportBatchEvent;
use App\Models\Leave\ImportMapping as LeaveImportMapping;
use App\Models\Leave\ImportRow as LeaveImportRow;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\ProcessingRun as LeaveProcessingRun;
use App\Models\Leave\RequestDay as LeaveRequestDay;
use App\Models\Leave\RequestDecision as LeaveRequestDecision;
use App\Models\Leave\RequestVersion as LeaveRequestVersion;
use App\Models\Leave\Setting as LeaveSetting;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

dataset('tenant models', [
    Branch::class, Location::class, Employee::class, BranchUser::class,
    CompanySequence::class, EmployeeImportBatch::class, EmployeeImportMapping::class, EmployeeVersion::class,
    Device::class, DeviceSetting::class, AttendanceImportBatch::class, ImportBatchEvent::class,
    AttendanceImportMapping::class, Transaction::class, TransactionSource::class, Person::class,
    CoverageInterval::class, DataIssue::class, ProcessingRun::class, Punch::class, Meal::class,
    Policy::class, ManualPunch::class, DayDecision::class, Day::class, RestDayEntitlement::class,
    Holiday::class, HolidaySource::class, HolidayVersion::class,
    AttendanceExport::class, AttendanceExportEvent::class,
    FollowUpCase::class, CaseEvent::class, ApproverMapping::class,
    ChatConversation::class, ChatTurn::class, ChatMessage::class, ChatToolCall::class,
    ChatProposal::class, ChatProposalEvent::class,
    NotificationBatch::class, NotificationMessage::class, NotificationRoute::class,
    NotificationMessageCase::class, NotificationAttempt::class,
    LeaveImportBatch::class, LeaveImportBatchEvent::class, LeaveImportMapping::class,
    LeaveBalanceBatch::class, LeaveBalanceBatchEvent::class, LeaveBalanceMapping::class,
    LeaveBalance::class, LeaveBalanceRow::class,
    LeaveType::class, LeaveRequest::class, LeaveImportRow::class, LeaveRequestVersion::class,
    LeaveRequestDecision::class, LeaveProcessingRun::class, LeaveRequestDay::class,
    LeaveCredit::class, LeaveAllocation::class, EntitlementConsumption::class,
]);

test('every kept tenant model isolates reads and builder writes and stamps creation', function (string $model): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $make = fn (Company $company, array $attributes = []) => $context->runForCompany($company, function () use ($model, $company, $attributes) {
        if ($model === BranchUser::class) {
            return BranchUser::factory()->create($attributes + [
                'company_id' => $company->id,
                'branch_id' => Branch::factory()->create(['company_id' => $company->id])->id,
                'user_id' => User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff'])->id,
            ]);
        }

        return $model::factory()->create($attributes);
    });

    $own = $make($a);
    $other = $make($b);

    $context->setCompany($a);
    expect($model::query()->pluck('id')->all())->toBe([$own->getKey()]);
    expect($model::find($other->getKey()))->toBeNull();
    expect($model::count())->toBe(1);
    expect($model::query()->whereKey($other->getKey())->update(['company_id' => $a->id]))->toBe(0);
    expect($model::query()->whereKey($other->getKey())->delete())->toBe(0);

    $attributes = $model === CompanySequence::class ? ['sequence_key' => 'employee_code', 'year' => 1] : [];
    $created = $make($a, $attributes + ['company_id' => null]);
    expect((int) $created->company_id)->toBe($a->id);
    expect(fn () => $make($a, ['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
})->with('tenant models');

test('one leave setting per company remains tenant scoped', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $own = $context->runForCompany($a, fn () => LeaveSetting::factory()->create());
    $other = $context->runForCompany($b, fn () => LeaveSetting::factory()->create());

    $context->setCompany($a);
    expect(LeaveSetting::query()->pluck('id')->all())->toBe([$own->id])
        ->and(LeaveSetting::find($other->id))->toBeNull()
        ->and(LeaveSetting::query()->whereKey($other->id)->update(['credit_expiry_days' => 30]))->toBe(0)
        ->and(fn () => LeaveSetting::factory()->create(['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
});

test('one notification setting per company remains tenant scoped', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $own = $context->runForCompany($a, fn () => NotificationSetting::factory()->create());
    $other = $context->runForCompany($b, fn () => NotificationSetting::factory()->create());

    $context->setCompany($a);
    expect(NotificationSetting::query()->pluck('id')->all())->toBe([$own->id])
        ->and(NotificationSetting::find($other->id))->toBeNull()
        ->and(NotificationSetting::query()->whereKey($other->id)->update(['mode' => 'auto']))->toBe(0)
        ->and(fn () => NotificationSetting::factory()->create(['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
});

test('chat change settings are off by default and tenant scoped', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $own = $context->runForCompany($a, fn () => ChatSetting::factory()->create());
    $other = $context->runForCompany($b, fn () => ChatSetting::factory()->create());

    $context->setCompany($a);
    expect($own->chat_changes_enabled)->toBeFalse()
        ->and(ChatSetting::query()->pluck('id')->all())->toBe([$own->id])
        ->and(ChatSetting::find($other->id))->toBeNull()
        ->and(ChatSetting::query()->whereKey($other->id)->update(['chat_changes_enabled' => true]))->toBe(0)
        ->and(fn () => ChatSetting::factory()->create(['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
});
