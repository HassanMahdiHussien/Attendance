<?php

use App\Enums\CaseStatus;
use App\Jobs\GenerateDocumentPdf;
use App\LegalLibrary\PageReader;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    ->in('Feature');

function actingInAdminTenant(TestCase $test, User $user, Company $company): void
{
    $test->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($company);
    app(TenantContext::class)->setCompany($company);
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
