<?php

use App\Jobs\GenerateDocumentPdf;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Storage::fake('local');
        Bus::fake([GenerateDocumentPdf::class]);
    })
    ->in('Feature');

function actingInAdminTenant(TestCase $test, User $user, Company $company): void
{
    $test->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($company);
    app(TenantContext::class)->setCompany($company);
}
