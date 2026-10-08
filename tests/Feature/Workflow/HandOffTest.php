<?php

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Exceptions\TransitionBlocked;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\HandOffService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('response hand-off validates witnesses and records the response stage', function (): void {
    Storage::fake('local');
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
    expect(fn () => $service->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::DeliveryRefused,
        ['witness_1_name' => 'One', 'witness_2_name' => 'Two', 'attempted_at' => now(),
            'attempt_location' => 'Gate', 'attempted_by_name' => 'HR'], $hr))->toThrow(TransitionBlocked::class);
    $signature = $service->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::DeliveryRefused,
        ['witness_1_name' => 'One', 'witness_2_name' => 'Two', 'attempted_at' => now(),
            'attempt_location' => 'Gate', 'attempted_by_name' => 'HR',
            'witness_scan' => UploadedFile::fake()->createWithContent('witnesses.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")], $hr);
    expect($signature->status)->toBe(SignatureStatus::DeliveryRefused);
    expect($signature->witnessScanAttachment->kind)->toBe(AttachmentKind::DeliveryProof);
    expect($signature->witnessScanAttachment->attachable_id)->toBe($case->id);
    expect($case->events()->where('event_type', 'response_handoff_recorded')->latest('id')->first()->payload['witness_scan_attachment_id'])
        ->toBe($signature->witness_scan_attachment_id);
    expect($case->fresh()->status)->toBe(CaseStatus::ResponseDeliveryRefused);
});

test('defense receipt refusal needs signed witness evidence', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::AwaitingEmployeeResponse,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    $data = ['witness_1_name' => 'First', 'witness_2_name' => 'Second'];
    expect(fn () => app(HandOffService::class)->record($case, SignaturePurpose::DefenseLetterReceipt,
        SignatureStatus::RefusedToSign, $data, $hr))->toThrow(TransitionBlocked::class);
    expect($case->events()->where('event_type', 'hand_off_recorded')->exists())->toBeFalse();
    $data['witness_scan'] = UploadedFile::fake()->createWithContent('witnesses.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    $signature = app(HandOffService::class)->record($case, SignaturePurpose::DefenseLetterReceipt,
        SignatureStatus::RefusedToSign, $data, $hr);
    expect($signature->witnessScanAttachment->kind)->toBe(AttachmentKind::DeliveryProof);
});

test('a failed refusal transaction removes the uploaded witness file', function (): void {
    Storage::fake('local');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::AwaitingEmployeeResponse,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    $events = Mockery::mock(CaseEventLogger::class);
    $events->shouldReceive('log')->andThrow(new RuntimeException('Simulated event failure'));
    app()->instance(CaseEventLogger::class, $events);
    expect(fn () => app(HandOffService::class)->record($case, SignaturePurpose::DefenseLetterReceipt,
        SignatureStatus::RefusedToSign, ['witness_1_name' => 'First', 'witness_2_name' => 'Second',
            'witness_scan' => UploadedFile::fake()->createWithContent('witnesses.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")], $hr))
        ->toThrow(RuntimeException::class);
    expect(Attachment::query()->where('kind', AttachmentKind::DeliveryProof->value)->exists())->toBeFalse();
    expect(Storage::disk('local')->allFiles('attachments'))->toBe([]);
});
