<?php

namespace App\Services\Companies;

use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Company;

class CompanySetup
{
    public function for(Company $company): array
    {
        $branches = Branch::query()->where('company_id', $company->id)->get();
        $branchReason = $branches->isEmpty() ? __('messages.setup.branch_missing') : null;
        foreach ($branches as $branch) {
            if ($branch->jurisdiction === null) {
                $branchReason = __('messages.setup.jurisdiction_missing', ['branch' => $branch->name]);
                break;
            }
        }

        $languages = $company->enabled_languages ?? [];
        $languageReason = in_array('ar', $languages, true) && in_array('en', $languages, true)
            ? null : __('messages.setup.languages_missing');

        return [
            $this->item('branches', $branchReason, BranchResource::getUrl(panel: 'admin', tenant: $company)),
            $this->item('languages', $languageReason, CompanySettings::getUrl(panel: 'admin', tenant: $company)),
        ];
    }

    public function complete(Company $company): bool
    {
        foreach ($this->for($company) as $item) {
            if (! $item['done']) {
                return false;
            }
        }

        return true;
    }

    private function item(string $key, ?string $reason, string $url): array
    {
        return ['key' => $key, 'label' => __('messages.setup.'.$key), 'done' => $reason === null,
            'reason' => $reason, 'url' => $url];
    }
}
