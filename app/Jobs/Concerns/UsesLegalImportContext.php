<?php

namespace App\Jobs\Concerns;

use App\Support\Tenancy\TenantContext;
use Closure;

trait UsesLegalImportContext
{
    private function withinImportContext(?int $companyId, Closure $callback): mixed
    {
        $context = app(TenantContext::class);

        return $companyId === null
            ? $context->runAcrossAllCompanies($callback)
            : $context->runForCompany($companyId, $callback);
    }
}
