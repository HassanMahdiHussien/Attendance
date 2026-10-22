<?php

use App\Enums\EmployeeExitStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ExitReviewEmployees;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\Employees\EmployeeHistory;
use App\Services\Employees\ExitReview;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create();
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
    $this->travelBack();
});

test('review starts without deactivation and confirmation applies only after the last working day', function (): void {
    $review = app(ExitReview::class);
    $review->start($this->employee, $this->actor);
    expect($this->employee->fresh()->exit_status)->toBe(EmployeeExitStatus::PendingReview)
        ->and($this->employee->fresh()->is_active)->toBeTrue();
    $review->confirm($this->employee, today()->toDateString(), $this->actor);
    expect($this->employee->fresh()->exit_status)->toBe(EmployeeExitStatus::Confirmed)
        ->and($this->employee->fresh()->is_active)->toBeTrue();
    $this->artisan('employees:apply-effective-changes')->assertSuccessful();
    expect($this->employee->fresh()->is_active)->toBeTrue();
    $this->travelTo(today()->addDay());
    $this->artisan('employees:apply-effective-changes')->assertSuccessful();
    $this->artisan('employees:apply-effective-changes')->assertSuccessful();
    expect($this->employee->fresh()->is_active)->toBeFalse();
});

test('dismissal requires a reason and the review page cannot show another company employee', function (): void {
    // Created before any panel request: once Filament has a tenant, it assigns new branches to that tenant.
    $other = Company::factory()->create();
    $otherEmployee = app(TenantContext::class)->runForCompany($other, fn () => Employee::factory()->create());
    $review = app(ExitReview::class);
    $review->start($this->employee, $this->actor);
    Livewire::test(ExitReviewEmployees::class)->assertSee($this->employee->name);
    $this->get(EmployeeResource::getUrl('exit-review', panel: 'admin', tenant: $this->company))->assertOk();
    $review->dismiss($this->employee, $this->actor, 'Included in a later list');
    expect($this->employee->fresh()->exit_status)->toBe(EmployeeExitStatus::None)
        ->and(AuditLog::query()->where('action', 'employee.exit.dismissed')->exists())->toBeTrue();

    Livewire::test(ExitReviewEmployees::class)->call('confirm', $otherEmployee->id)->assertNotFound();
    expect($this->get(EmployeeResource::getUrl('exit-review', panel: 'admin', tenant: $other))->status())->toBeIn([403, 404]);
});

test('a past last working day deactivates only when HR confirms', function (): void {
    $review = app(ExitReview::class);
    app(EmployeeHistory::class)->record($this->employee, ['department' => 'Operations'], today(), 'manual', $this->actor);
    $versionsBefore = EmployeeVersion::query()->where('employee_id', $this->employee->id)->pluck('id')->all();
    $linked = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff, 'employee_id' => $this->employee->id]);
    $review->start($this->employee, $this->actor);
    expect($this->employee->fresh()->is_active)->toBeTrue();
    $review->confirm($this->employee, today()->subDay(), $this->actor);
    expect($this->employee->fresh()->is_active)->toBeFalse()
        ->and($this->employee->fresh()->exit_status)->toBe(EmployeeExitStatus::Confirmed)
        ->and(EmployeeVersion::query()->where('employee_id', $this->employee->id)->pluck('id')->all())->toBe($versionsBefore)
        ->and($linked->fresh()->employee_id)->toBe($this->employee->id);
});
