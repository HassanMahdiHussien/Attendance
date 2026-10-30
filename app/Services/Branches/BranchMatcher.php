<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\BranchAlias;
use App\Models\Company;
use Illuminate\Support\Collection;

class BranchMatcher
{
    private array $lookup = [];

    public static function normalise(string $value): string
    {
        $value = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{08D3}-\x{08FF}]/u', '', $value);
        $value = strtr($value, ["\u{0623}" => "\u{0627}", "\u{0625}" => "\u{0627}",
            "\u{0622}" => "\u{0627}", "\u{0629}" => "\u{0647}", "\u{0649}" => "\u{064A}"]);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    public function forCompany(Company $company, bool $withoutScopes = false): self
    {
        $query = $withoutScopes ? Branch::withoutGlobalScopes() : Branch::query();
        $branches = $query->where('company_id', $company->id)->get();
        $aliases = ($withoutScopes ? BranchAlias::withoutGlobalScopes() : BranchAlias::query())
            ->where('company_id', $company->id)->get()->groupBy('branch_id');
        $this->lookup = [];
        foreach ($branches as $branch) {
            foreach ([$branch->name, ...$aliases->get($branch->id, collect())->pluck('name')->all()] as $name) {
                $key = self::normalise($name);
                $this->lookup[$key][$branch->id] = $branch;
            }
        }

        return $this;
    }

    public function matches(string $name): Collection
    {
        return collect(array_values($this->lookup[self::normalise($name)] ?? []));
    }

    public function match(string $name): ?Branch
    {
        $matches = $this->matches($name);

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
