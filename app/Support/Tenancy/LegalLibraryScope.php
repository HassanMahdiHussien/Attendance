<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class LegalLibraryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isSet()) {
            throw new MissingTenantContext('A company context is required for '.$model::class);
        }

        if (! $context->isAllCompanies()) {
            $builder->where(function (Builder $query) use ($model, $context): void {
                $query->whereNull($model->qualifyColumn('company_id'))
                    ->orWhere($model->qualifyColumn('company_id'), $context->companyId());
            });
        }
    }
}
