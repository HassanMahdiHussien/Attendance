<?php

use App\Enums\UserRole;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\Reporting\HrJourney;
use App\Support\Tenancy\TenantContext;

test('journey advances through upload, device check, results and review despite old open issues', function (): void {
    $company = Company::factory()->create();
    $this->actingAs(User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]));
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $journey = app(HrJourney::class);
        $current = fn (array $freshness): string => collect($journey->for($company, $freshness)['steps'])
            ->firstWhere('state', 'current')['key'];
        expect($current(['results_updating' => false]))->toBe('upload');

        $batch = ImportBatch::factory()->create();
        ImportBatchEvent::factory()->create(['batch_id' => $batch->id, 'state' => 'completed']);
        $device = Device::factory()->create(['status' => 'pending']);
        expect($current(['results_updating' => false]))->toBe('check');

        $device->update(['status' => 'approved']);
        DataIssue::factory()->create(['type' => 'unknown_person']);
        expect($current(['results_updating' => true]))->toBe('results')
            ->and($current(['results_updating' => false]))->toBe('review');
    });
});
