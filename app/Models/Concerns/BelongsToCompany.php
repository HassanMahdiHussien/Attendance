<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContext;
use App\Exceptions\TenantMismatch;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::saving(function ($model): void {
            $context = app(TenantContext::class);
            if (! $context->isSet()) {
                throw new MissingTenantContext('A company context is required for '.$model::class);
            }

            foreach ($model->companyParentRelations() as $name) {
                /** @var BelongsTo $relation */
                $relation = $model->{$name}();
                $parentId = $model->getAttributeValue($relation->getForeignKeyName());
                if ($parentId === null) {
                    continue;
                }

                $parent = $relation->getRelated()->newQueryWithoutScopes()->find($parentId);
                if ($parent === null) {
                    continue;
                }

                $parentCompany = $parent->getAttribute('company_id');
                if ($model->company_id !== null && (int) $model->company_id !== (int) $parentCompany) {
                    throw new TenantMismatch('Parent belongs to another company.');
                }
                $model->company_id = $parentCompany;
            }

            if ($context->isAllCompanies()) {
                if ($model->company_id === null) {
                    throw new MissingTenantContext('A company_id is required in all-companies context.');
                }
            } elseif ($model->company_id === null) {
                $model->company_id = $context->companyId();
            } elseif ((int) $model->company_id !== $context->companyId()) {
                throw new TenantMismatch('Record belongs to another company.');
            }

            foreach ($model->companyUserForeignKeys() as $key) {
                $userId = $model->getAttributeValue($key);
                if ($userId === null) {
                    continue;
                }

                $user = User::find($userId);
                if ($user !== null && $user->company_id !== null
                    && (int) $user->company_id !== (int) $model->company_id) {
                    throw new TenantMismatch('User belongs to another company.');
                }
            }
        });
    }

    protected function companyParentRelations(): array
    {
        return $this->companyParents ?? [];
    }

    protected function companyUserForeignKeys(): array
    {
        return $this->companyUserKeys ?? [];
    }
}
