<?php

use App\Ai\ClaudeClient;
use App\Enums\CaseStatus;
use App\Exceptions\TransitionBlocked;
use App\Jobs\SuggestSignaturePresence;
use App\Livewire\Cases\SignedScan;
use App\Models\AiGenerationLog;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\Signature;
use App\Models\SignaturePresenceSuggestion;
use App\Models\User;
use App\Services\Cases\SignaturePresence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function signedScanReadingFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id]);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::AwaitingCommitteeSignatures->value]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations',
        'is_locked' => true, 'status' => 'issued']);
    $attendees = collect(range(1, 3))->map(fn () => CommitteeSessionAttendance::factory()->create(['case_id' => $case->id]));

    return compact('company', 'hr', 'case', 'document', 'attendees');
}

test('checks remain bound to their completed scan and a newer unchecked scan blocks override', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id]);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::AwaitingCommitteeSignatures->value]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations',
        'is_locked' => true, 'status' => 'issued']);
    $attendees = collect(range(1, 3))->map(fn () => CommitteeSessionAttendance::factory()->create(['case_id' => $case->id]));
    $choices = $attendees->mapWithKeys(fn ($row, $index) => [$row->id => $index === 0 ? 'empty' : 'filled'])->all();
    $presence = app(SignaturePresence::class);
    $presence->recordSignedScan($document, UploadedFile::fake()->image('first.png'), $choices, $hr);
    $first = $presence->latestScan($document);
    expect($presence->latestChecks($document))->toHaveCount(3);
    $presence->uploadSignedScan($document, UploadedFile::fake()->image('second.png'), $hr, false);
    expect($presence->latestChecks($document)->first()->attachment_id)->toBe($first->id);
    expect(fn () => $presence->overrideEmpty($document, 'Checked paper separately', $hr))->toThrow(TransitionBlocked::class);
    expect(fn () => $presence->recordPresence($document, $first, $choices, $hr))->toThrow(TransitionBlocked::class);
});

test('suggestions prefill clear boxes, require a choice for unclear, and retain HR provenance', function (): void {
    $f = signedScanReadingFixture();
    Bus::fake([SuggestSignaturePresence::class]);
    $presence = app(SignaturePresence::class);
    $scan = $presence->uploadSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['hr']);
    $fake = new FakeClaudeClient;
    $fake->push(['results' => [
        ['attendance_id' => $f['attendees'][0]->id, 'result' => 'appears_filled', 'reason' => 'حبر ظاهر'],
        ['attendance_id' => $f['attendees'][1]->id, 'result' => 'appears_empty', 'reason' => 'لا يظهر حبر'],
        ['attendance_id' => $f['attendees'][2]->id, 'result' => 'unclear', 'reason' => 'الصورة غير واضحة'],
    ]]);
    app()->instance(ClaudeClient::class, $fake);
    $job = new SuggestSignaturePresence($f['case']->id, $f['company']->id, $f['hr']->id,
        $f['document']->id, $scan->id, $f['case']->fresh()->ai_request_id);
    app()->call([$job, 'handle']);
    $suggestion = SignaturePresenceSuggestion::query()->firstOrFail();
    expect($suggestion->results)->toHaveCount(3);
    $component = Livewire::actingAs($f['hr'])->test(SignedScan::class, ['case' => (string) $f['case']->id]);
    expect($component->get('presence.'.$f['attendees'][0]->id))->toBe('filled')
        ->and($component->get('presence.'.$f['attendees'][1]->id))->toBe('empty')
        ->and($component->get('presence.'.$f['attendees'][2]->id))->toBe('');
    $component->call('recordReadPresence')->assertHasErrors(['notice'])
        ->assertSee('اختر حالة كل خانة توقيع')->assertSee('border-red');
    expect(Signature::query()->where('attachment_id', $scan->id)->count())->toBe(0);
    $component->set('presence.'.$f['attendees'][2]->id, 'filled')->call('recordReadPresence')->assertHasNoErrors();
    $rows = Signature::query()->where('attachment_id', $scan->id)->get();
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->checked_by)->toBe($f['hr']->id)
            ->and($row->presence_suggestion_id)->toBe($suggestion->id)
            ->and($row->attachment_id)->toBe($scan->id)
            ->and($row->ai_suggestion)->not->toBeNull();
    }
});

test('failed reading keeps the one-submit manual form usable and an old suggestion cannot be applied to a new scan', function (): void {
    $f = signedScanReadingFixture();
    Bus::fake([SuggestSignaturePresence::class]);
    $presence = app(SignaturePresence::class);
    $first = $presence->uploadSignedScan($f['document'], UploadedFile::fake()->image('first.png'), $f['hr']);
    $job = new SuggestSignaturePresence($f['case']->id, $f['company']->id, $f['hr']->id,
        $f['document']->id, $first->id, $f['case']->fresh()->ai_request_id);
    $job->failed(new RuntimeException('AI unavailable'));
    $choices = $f['attendees']->mapWithKeys(fn ($row) => [$row->id => 'filled'])->all();
    Livewire::actingAs($f['hr'])->test(SignedScan::class, ['case' => (string) $f['case']->id])
        ->set('scan', UploadedFile::fake()->image('manual.png'))->set('presence', $choices)->call('upload')->assertHasNoErrors();
    expect($f['case']->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded);

    $other = signedScanReadingFixture();
    $old = $presence->uploadSignedScan($other['document'], UploadedFile::fake()->image('old.png'), $other['hr'], false);
    $suggestion = SignaturePresenceSuggestion::create(['company_id' => $other['company']->id,
        'case_id' => $other['case']->id, 'document_id' => $other['document']->id, 'attachment_id' => $old->id,
        'results' => [], 'ai_generation_log_id' => AiGenerationLog::factory()->create(['company_id' => $other['company']->id])->id,
        'created_at' => now()]);
    $new = $presence->uploadSignedScan($other['document'], UploadedFile::fake()->image('new.png'), $other['hr'], false);
    $newChoices = $other['attendees']->mapWithKeys(fn ($row) => [$row->id => 'filled'])->all();
    expect(fn () => $presence->recordPresence($other['document'], $new, $newChoices, $other['hr'], $suggestion->id))
        ->toThrow(ModelNotFoundException::class);
    expect(Signature::query()->where('attachment_id', $new->id)->count())->toBe(0);
});

test('signed scan UI and actions deny unassigned HR, committee, reviewer and reporter accounts', function (): void {
    $f = signedScanReadingFixture();
    $assigned = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $f['case']->forceFill(['assigned_hr_user_id' => $assigned->id])->save();
    $this->actingAs($assigned);
    $html = $this->get('/cases/'.$f['case']->id.'/signed-scan')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $actors = [User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']),
        User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'committee_member']),
        User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'reporter']),
        User::factory()->create(['company_id' => null, 'primary_role' => 'external_legal_reviewer',
            'law_firm_partner_id' => LawFirmPartner::factory()->create()->id])];
    foreach ($actors as $actor) {
        $this->actingAs($actor)->get('/cases/'.$f['case']->id.'/signed-scan')->assertNotFound();
        foreach (['uploadForReading', 'recordReadPresence'] as $method) {
            $this->actingAs($actor)->postJson(Livewire::getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => $method, 'params' => []]],
            ]]], ['X-Livewire' => 'true'])->assertNotFound();
        }
    }
    app(TenantContext::class)->setCompany($f['company']);
    expect(Signature::query()->where('document_id', $f['document']->id)->count())->toBe(0);
});
