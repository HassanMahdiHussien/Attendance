<?php

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Ai\ScanBlocks;
use App\Ai\SdkClaudeClient;
use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\Vote;
use App\Exceptions\TransitionBlocked;
use App\Jobs\TranscribeResponseScan;
use App\LegalLibrary\PdfPages;
use App\Livewire\Cases\ShowCase;
use App\Models\AiGenerationLog;
use App\Models\Attachment;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\ResponseTranscription;
use App\Models\User;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ResponseReading;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function readingCase(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id]);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::ResponseScannedUploaded->value]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    Storage::disk('local')->put('scan.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $scan = Attachment::factory()->create(['attachable_type' => 'case', 'attachable_id' => $case->id,
        'kind' => 'employee_response_scan', 'path' => 'scan.png', 'mime_type' => 'image/png', 'size_bytes' => 70]);

    return [$case->fresh(), $hr, $scan];
}

test('pending and unconfirmed response attempts never enter drafting payload', function (): void {
    [$case, $hr, $scan] = readingCase();
    Bus::fake([TranscribeResponseScan::class]);
    $reading = app(ResponseReading::class);
    expect(fn () => $reading->request($case, $hr, false))->toThrow(TransitionBlocked::class);
    $reading->request($case, $hr, true);
    $token = $case->fresh()->ai_request_id;
    expect($case->fresh()->confirmedResponseText())->toBeNull();
    ResponseTranscription::create(['company_id' => $case->company_id, 'case_id' => $case->id,
        'attachment_id' => $scan->id, 'origin' => 'ai', 'text' => 'unconfirmed', 'created_at' => now()]);
    expect(app(PayloadBuilder::class)->build($case->fresh(), DocumentType::Recommendations, collect())['employee_response'])->toBeNull();
    $reading->confirm($case, 'checked text', 'ai_checked', $hr);
    (new TranscribeResponseScan($case->id, $case->company_id, $hr->id, $scan->id, $token))->failed(new RuntimeException('late failure'));
    expect($case->fresh()->confirmedResponseText())->toBe('checked text');
    $reading->correct($case, 'Image comparison', $hr);
    expect($case->fresh()->confirmedResponseText())->toBeNull();
    expect(app(PayloadBuilder::class)->build($case->fresh(), DocumentType::Recommendations, collect())['employee_response'])->toBeNull();
});

test('scan blocks use SDK source keys and logged input excludes base64', function (): void {
    [$case, $hr, $scan] = readingCase();
    $block = app(ScanBlocks::class)->for($scan);
    expect($block['source'])->toHaveKeys(['type', 'mediaType', 'data']);
    expect($block['source'])->not->toHaveKey('media_type');
    $messages = [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => 'Read']]]];
    $safe = (new SdkClaudeClient)->safeMessages($messages, $scan->id);
    expect($safe[0]['content'][0])->toHaveKeys(['type', 'media_type', 'attachment_id', 'sha256', 'bytes']);
    expect(json_encode($safe))->not->toContain($block['source']['data']);
    $fake = new FakeClaudeClient;
    $fake->push(['text' => 'example', 'illegible' => false]);
    $fake->structured('transcribe_response', [], $messages, ['company_id' => $case->company_id,
        'case_id' => $case->id, 'actor_user_id' => $hr->id, 'attachment_id' => $scan->id], 'test', 'high');
    expect(AiGenerationLog::query()->latest('id')->first()->input)->not->toContain($block['source']['data']);
});

test('PDF source uses the SDK mediaType key and refuses more than twenty pages', function (): void {
    [$case, $hr, $scan] = readingCase();
    Storage::disk('local')->put('scan.pdf', '%PDF-1.4 test');
    $pdf = Attachment::factory()->create(['attachable_type' => 'case', 'attachable_id' => $case->id,
        'kind' => 'employee_response_scan', 'path' => 'scan.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 13]);
    $pages = new class extends PdfPages
    {
        public int $number = 2;

        public function count(string $pdf): int
        {
            return $this->number;
        }
    };
    $blocks = new ScanBlocks($pages);
    expect($blocks->for($pdf)['source'])->toHaveKey('mediaType', 'application/pdf');
    $pages->number = 21;
    expect(fn () => $blocks->for($pdf))->toThrow(ValidationException::class);
});

test('only the company administrator or assigned HR can request a reading', function (): void {
    [$case, $admin] = readingCase();
    $assigned = User::factory()->create(['company_id' => $case->company_id, 'primary_role' => 'hr_staff']);
    $unassigned = User::factory()->create(['company_id' => $case->company_id, 'primary_role' => 'hr_staff']);
    $case->forceFill(['assigned_hr_user_id' => $assigned->id])->save();
    foreach ([$unassigned, ...array_map(fn ($role) => User::factory()->create([
        'company_id' => $case->company_id, 'primary_role' => $role]),
        ['committee_member', 'reporter']), User::factory()->create(['company_id' => null,
            'primary_role' => 'external_legal_reviewer',
            'law_firm_partner_id' => LawFirmPartner::factory()->create()->id])] as $actor) {
        expect(fn () => app(ResponseReading::class)->request($case, $actor, true))->toThrow(AuthorizationException::class);
    }
    Bus::fake([TranscribeResponseScan::class]);
    app(ResponseReading::class)->request($case, $assigned, true);
    expect($case->fresh()->ai_request_document_type)->toBe('response_transcription');
    Livewire::actingAs($unassigned)->test(ShowCase::class, ['case' => (string) $case->id])->assertNotFound();
});

test('a requested reading is only an attempt until HR confirms and a correction clears context', function (): void {
    [$case, $hr, $scan] = readingCase();
    Bus::fake([TranscribeResponseScan::class]);
    $reading = app(ResponseReading::class);
    expect(fn () => $reading->request($case, $hr, false))->toThrow(TransitionBlocked::class);
    expect($case->events()->where('event_type', 'response_reading_requested')->exists())->toBeFalse();

    $reading->request($case, $hr, true);
    expect($case->events()->where('event_type', 'response_reading_requested')->latest('id')->first()->payload['no_sensitive_data_confirmed'])->toBeTrue();
    $fake = new FakeClaudeClient;
    $fake->push(['text' => 'نص آلي', 'illegible' => false]);
    app()->instance(ClaudeClient::class, $fake);
    $job = new TranscribeResponseScan($case->id, $case->company_id, $hr->id, $scan->id, $case->fresh()->ai_request_id);
    app()->call([$job, 'handle']);
    expect(ResponseTranscription::query()->where('origin', 'ai')->count())->toBe(1)
        ->and($case->fresh()->employee_response_text)->toBeNull()
        ->and($case->fresh()->confirmedResponseText())->toBeNull();

    $reading->confirm($case, 'النص المصحح', 'ai_checked', $hr);
    $confirmed = $case->fresh();
    expect($confirmed->status)->toBe(CaseStatus::AiIngestedResponse)
        ->and($confirmed->confirmedResponseText())->toBe('النص المصحح')
        ->and($confirmed->employee_response_checked_by)->toBe($hr->id)
        ->and($confirmed->employee_response_checked_at)->not->toBeNull()
        ->and($confirmed->employee_response_origin)->toBe('ai_checked')
        ->and(app(PayloadBuilder::class)->build($confirmed, DocumentType::Recommendations, collect())['employee_response'])->not->toBeNull();
    Livewire::actingAs($hr)->test(ShowCase::class, ['case' => (string) $case->id])
        ->assertSee('أكّد النص: '.$hr->name)->assertSee('بمساعدة الذكاء الاصطناعي')->assertSee('تصحيح النص');
    $reading->correct($case, 'المسح أوضح الكلمة', $hr);
    $correcting = $case->fresh();
    expect($correcting->status)->toBe(CaseStatus::ResponseScannedUploaded)
        ->and($correcting->employee_response_text)->toBeNull()
        ->and($correcting->confirmedResponseText())->toBeNull()
        ->and(app(PayloadBuilder::class)->build($correcting, DocumentType::Recommendations, collect())['employee_response'])->toBeNull();
    expect($case->events()->where('event_type', 'response_transcription_correction')->latest('id')->first()->payload['reason'])->toBe('المسح أوضح الكلمة');
    $reading->confirm($case, 'النص النهائي', 'human', $hr);
    expect($case->fresh()->confirmedResponseText())->toBe('النص النهائي')
        ->and($case->fresh()->employee_response_origin)->toBe('human');
    Livewire::actingAs($hr)->test(ShowCase::class, ['case' => (string) $case->id])->assertSee('إدخال يدوي');
});

test('late reading workers cannot save after manual confirmation or a newer scan', function (): void {
    [$case, $hr, $scan] = readingCase();
    Bus::fake([TranscribeResponseScan::class]);
    $reading = app(ResponseReading::class);
    $reading->request($case, $hr, true);
    $old = new TranscribeResponseScan($case->id, $case->company_id, $hr->id, $scan->id, $case->fresh()->ai_request_id);
    $reading->confirm($case, 'كتبه الموظف', 'human', $hr);
    expect($case->fresh()->ai_request_id)->toBeNull()
        ->and($case->fresh()->ai_request_attachment_id)->toBeNull()
        ->and($case->events()->where('event_type', 'ai_request_superseded')->exists())->toBeTrue();
    $fake = new FakeClaudeClient;
    app()->instance(ClaudeClient::class, $fake);
    app()->call([$old, 'handle']);
    expect($fake->requests)->toBeEmpty()
        ->and(ResponseTranscription::query()->where('origin', 'ai')->count())->toBe(0);

    $reading->correct($case, 'إعادة القراءة', $hr);
    $reading->request($case, $hr, true);
    $pending = new TranscribeResponseScan($case->id, $case->company_id, $hr->id, $scan->id, $case->fresh()->ai_request_id);
    $newScan = Attachment::factory()->create(['attachable_type' => 'case', 'attachable_id' => $case->id,
        'kind' => 'employee_response_scan', 'path' => 'scan.png', 'mime_type' => 'image/png', 'size_bytes' => 70]);
    app()->call([$pending, 'handle']);
    expect($fake->requests)->toBeEmpty()
        ->and(ResponseTranscription::query()->where('origin', 'ai')->count())->toBe(0);
    $case->forceFill(['ai_request_id' => 'newer-token', 'ai_request_attachment_id' => $newScan->id])->save();
    $pending->failed(new RuntimeException('late failure'));
    expect($case->fresh()->ai_request_id)->toBe('newer-token');
});

test('reconciliation expires a reading token without moving the case', function (): void {
    [$case, $hr] = readingCase();
    Bus::fake([TranscribeResponseScan::class]);
    app(ResponseReading::class)->request($case, $hr, true);
    $case->forceFill(['ai_request_started_at' => now()->subMinutes(21)])->save();
    Artisan::call('ai:reconcile-drafting');
    expect($case->fresh()->status)->toBe(CaseStatus::ResponseScannedUploaded)
        ->and($case->fresh()->ai_request_id)->toBeNull()
        ->and($case->fresh()->ai_request_attachment_id)->toBeNull()
        ->and($case->events()->where('event_type', 'ai_request_expired')->exists())->toBeTrue();
});

test('recording the committee session supersedes a pending response reading', function (): void {
    [$case, $hr, $scan] = readingCase();
    Bus::fake([TranscribeResponseScan::class]);
    $case->forceFill(['defense_letter_issued_at' => now()->subDay()])->save();
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDays(2)]);
    $members = collect(PoolRole::cases())->map(fn ($role) => CommitteePoolMember::factory()->create([
        'mandate_id' => $mandate->id, 'role_type' => $role]));
    app(ResponseReading::class)->request($case, $hr, true);
    $job = new TranscribeResponseScan($case->id, $case->company_id, $hr->id, $scan->id, $case->fresh()->ai_request_id);
    app(CommitteeSessionService::class)->recordSession($case,
        $members->map(fn ($member) => ['pool_member_id' => $member->id, 'vote' => Vote::Approve])->all(),
        $members->first()->id, 'محضر الجلسة', Outcome::RecommendationOnly, DefenseAcceptance::Rejected,
        $hr, $members->first()->id);
    $fake = new FakeClaudeClient;
    app()->instance(ClaudeClient::class, $fake);
    app()->call([$job, 'handle']);
    expect($case->fresh()->status)->toBe(CaseStatus::CommitteeSessionHeld)
        ->and($case->fresh()->ai_request_id)->toBeNull()
        ->and($case->events()->where('event_type', 'ai_request_superseded')->exists())->toBeTrue()
        ->and($fake->requests)->toBeEmpty()
        ->and(ResponseTranscription::query()->where('origin', 'ai')->count())->toBe(0);
});
