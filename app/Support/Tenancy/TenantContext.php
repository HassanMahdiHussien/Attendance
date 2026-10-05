<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;

class TenantContext
{
    private ?int $companyId = null;

    private bool $allCompanies = false;

    public function setCompany(Company|int $company): void
    {
        $this->companyId = $company instanceof Company ? $company->getKey() : $company;
        $this->allCompanies = false;
    }

    public function setAllCompanies(): void
    {
        $this->companyId = null;
        $this->allCompanies = true;
    }

    public function clear(): void
    {
        $this->companyId = null;
        $this->allCompanies = false;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function isAllCompanies(): bool
    {
        return $this->allCompanies;
    }

    public function isSet(): bool
    {
        return $this->companyId !== null || $this->allCompanies;
    }

    public function runForCompany(Company|int $company, Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousAll = $this->allCompanies;
        $this->setCompany($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->allCompanies = $previousAll;
        }
    }

    public function runAcrossAllCompanies(Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousAll = $this->allCompanies;
        $this->setAllCompanies();

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->allCompanies = $previousAll;
        }
    }
}
