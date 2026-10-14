<?php

use App\Enums\CaseStatus;
use App\Enums\Jurisdiction;
use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Jobs\GenerateDocumentPdf;
use App\LegalLibrary\PageReader;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Tests\Fakes\FakePageReader;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        app()->setLocale('en');
        Storage::fake('local');
        Bus::fake([GenerateDocumentPdf::class]);
        app()->bind(PageReader::class, FakePageReader::class);
    })
    ->in('Feature', 'Browser');

function actingInAdminTenant(TestCase $test, User $user, Company $company): void
{
    $test->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($company);
    app(TenantContext::class)->setCompany($company);
}

function completeCompanySetup(Company $company): array
{
    app(TenantContext::class)->setCompany($company);
    $company->update(['internal_regulation_answer' => 'no', 'internal_regulation_answered_at' => now()]);
    $logo = "letterheads/{$company->id}/test.png";
    $logoBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
    Storage::disk('local')->put($logo, $logoBytes);
    foreach ($company->enabled_languages as $language) {
        $latest = CompanyLetterhead::query()->where('company_id', $company->id)
            ->where('language_code', $language)->orderByDesc('version')->first();
        CompanyLetterhead::create(['company_id' => $company->id, 'language_code' => $language,
            'version' => ($latest?->version ?? 0) + 1, 'name' => $company->name,
            'footer_text' => 'CONFIDENTIAL', 'header_mode' => 'logo_text', 'logo_path' => $logo,
            'logo_sha256' => hash('sha256', $logoBytes),
            'needs_review' => false, 'created_at' => now()]);
    }
    $branch = Branch::query()->where('company_id', $company->id)->first()
        ?? Branch::factory()->create(['company_id' => $company->id, 'jurisdiction' => Jurisdiction::FederalIraq]);
    $mandate = CommitteeMandate::query()->where('company_id', $company->id)
        ->inEffectOn(now('Asia/Baghdad')->toDateString())->orderByDesc('effective_from')->first()
        ?? CommitteeMandate::factory()->create(['company_id' => $company->id, 'effective_from' => '2025-01-01']);
    $users = [];
    foreach ([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember, 'is_active' => true]);
        CommitteePoolMember::create(['company_id' => $company->id, 'mandate_id' => $mandate->id,
            'branch_id' => null, 'role_type' => $role, 'person_name' => $user->name, 'user_id' => $user->id]);
        $users[$role->value] = $user;
    }

    return ['branch' => $branch, 'mandate' => $mandate, 'users' => $users];
}

function moveCaseToCommitteeStage(DisciplinaryCase $case): void
{
    // Factory cases start as drafts; Doc A drafts exist only from the committee stage on.
    if ($case->status !== CaseStatus::Draft) {
        return;
    }
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => 'committee_session_held']);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
}

function livewireSnapshotForComponent(string $html, string $componentClass): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] ?? null;
        if ($name !== null && app('livewire.factory')->resolveComponentClass($name) === $componentClass) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Snapshot for {$componentClass} was not rendered.");
}

/**
 * Browser page whose fill() waits until Livewire has bound the input (Alpine's _x_model), then types and re-types
 * once if Livewire's boot wiped the value. Typing straight after a wire:navigate swap or a fresh load raced that boot.
 */
final class BoundPage
{
    public function __construct(private object $page) {}

    public function fill(string $field, string $value): self
    {
        $selector = json_encode($field);
        $find = "document.getElementById({$selector}) || document.querySelector('[name=' + JSON.stringify({$selector}) + ']')";
        $deadline = microtime(true) + 10;
        while (! $this->page->script("() => { const el = {$find}; return !! (el && el._x_model); }")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Input [{$field}] was never bound by Livewire.");
            }
            usleep(100_000);
        }
        $this->page->fill($field, $value);
        if ($this->page->script("() => ({$find}).value") !== $value) {
            $this->page->fill($field, $value);
        }

        return $this;
    }

    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->page->{$method}(...$arguments);

        return $result instanceof Webpage || $result instanceof PendingAwaitablePage
            ? new self($result) : $result;
    }
}

function visitBound(string $url): BoundPage
{
    return new BoundPage(visit($url));
}

// Locally the app is read through Docker's slow Windows drive mount, where a first page load can exceed Playwright's
// 5 s default; allow 20 s so browser tests fail on behaviour, not on the dev filesystem.
pest()->browser()->timeout(20_000);
