<?php

use App\Enums\CaseStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Exceptions\TransitionBlocked;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\HandOffService;
use App\Support\Tenancy\TenantContext;

test('response hand-off validates witnesses and records the response stage', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::AwaitingEmployeeResponse,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    $service = app(HandOffService::class);
    expect(fn () => $service->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::RefusedToSign,
        ['witness_1_name' => 'One'], $hr))->toThrow(TransitionBlocked::class);
    expect(fn () => $service->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::DeliveryRefused,
        ['witness_1_name' => 'One', 'witness_2_name' => 'Two'], $hr))->toThrow(TransitionBlocked::class);
    $signature = $service->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::DeliveryRefused,
        ['witness_1_name' => 'One', 'witness_2_name' => 'Two', 'attempted_at' => now(),
            'attempt_location' => 'Gate', 'attempted_by_name' => 'HR'], $hr);
    expect($signature->status)->toBe(SignatureStatus::DeliveryRefused);
    expect($case->fresh()->status)->toBe(CaseStatus::ResponseDeliveryRefused);
});
