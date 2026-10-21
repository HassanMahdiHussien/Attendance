<?php

use App\Models\Company;
use App\Services\Companies\CompanySetup;
use App\Support\Tenancy\TenantContext;

test('setup lists missing branches and accepts a configured company', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $setup = app(CompanySetup::class);
    expect(collect($setup->for($company))->pluck('key')->all())->toBe(['branches', 'languages']);
    expect(collect($setup->for($company))->firstWhere('key', 'branches')['done'])->toBeFalse();

    completeCompanySetup($company);
    expect($setup->complete($company->fresh()))->toBeTrue();
});
