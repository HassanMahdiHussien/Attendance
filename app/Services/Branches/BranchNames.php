<?php

namespace App\Services\Branches;

use App\Enums\Jurisdiction;
use App\Models\Branch;
use App\Models\BranchAlias;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BranchNames
{
    public function save(Branch $branch, array $names): void
    {
        Gate::authorize('update', $branch);
        DB::transaction(function () use ($branch, $names): void {
            Company::query()->whereKey($branch->company_id)->lockForUpdate()->firstOrFail();
            $clean = $this->validateNames($branch, $names);
            $branch->aliases()->get()->each->delete();
            foreach ($clean as $key => $name) {
                $branch->aliases()->create(['company_id' => $branch->company_id, 'name' => $name, 'normalized_name' => $key]);
            }
        });
    }

    public function validateNames(Branch $branch, array $names): array
    {
        $this->assertAvailable($branch, $branch->name);
        $clean = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $key = BranchMatcher::normalise($name);
            if ($key === '') {
                continue;
            }
            $this->assertAvailable($branch, $name);
            if ($key !== BranchMatcher::normalise($branch->name)) {
                $clean[$key] = $name;
            }
        }

        return $clean;
    }

    public function assertAvailable(Branch $branch, string $name): void
    {
        $key = BranchMatcher::normalise($name);
        $others = Branch::withoutGlobalScopes()->where('company_id', $branch->company_id)
            ->when($branch->exists, fn ($query) => $query->where('id', '!=', $branch->id))->get();
        $displayClash = $others->contains(fn (Branch $other): bool => BranchMatcher::normalise($other->name) === $key);
        $aliasClash = BranchAlias::withoutGlobalScopes()->where('company_id', $branch->company_id)
            ->where('normalized_name', $key)
            ->when($branch->exists, fn ($query) => $query->where('branch_id', '!=', $branch->id))->exists();
        if ($key === '' || $displayClash || $aliasClash) {
            throw ValidationException::withMessages(['name' => __('messages.branch_names.duplicate')]);
        }
    }

    public function link(Company $company, string $name, int $branchId): void
    {
        $branch = Branch::query()->where('company_id', $company->id)->findOrFail($branchId);
        Gate::authorize('update', $branch);
        $this->assertAvailable($branch, $name);
        if (BranchMatcher::normalise($branch->name) === BranchMatcher::normalise($name)) {
            return;
        }
        $branch->aliases()->firstOrCreate(['normalized_name' => BranchMatcher::normalise($name)],
            ['company_id' => $company->id, 'name' => trim($name)]);
    }

    public function create(Company $company, string $name, string $jurisdiction): Branch
    {
        Gate::authorize('create', Branch::class);
        if (Jurisdiction::tryFrom($jurisdiction) === null) {
            throw ValidationException::withMessages(['jurisdiction' => __('messages.branch_names.jurisdiction_required')]);
        }

        return DB::transaction(function () use ($company, $name, $jurisdiction): Branch {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $branch = new Branch(['company_id' => $company->id, 'name' => trim($name), 'jurisdiction' => $jurisdiction]);
            $this->assertAvailable($branch, $name);
            $branch->save();

            return $branch;
        });
    }
}
