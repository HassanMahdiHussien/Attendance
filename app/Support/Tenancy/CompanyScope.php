<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isSet()) {
            throw new MissingTenantContext('A company context is required for '.$model::class);
        }

        if (! $context->isAllCompanies()) {
            $builder->where($model->qualifyColumn('company_id'), $context->companyId());
        }
    }
}
