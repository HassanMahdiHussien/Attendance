<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\Company;
use App\Models\Signature;
use App\Support\Tenancy\TenantContext;

test('signature provenance and presence cannot be rewritten after insertion', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $signature = Signature::factory()->create();
    $signature->ai_suggestion = 'appears_filled';
    expect(fn () => $signature->save())->toThrow(ImmutableRecordException::class);
});
