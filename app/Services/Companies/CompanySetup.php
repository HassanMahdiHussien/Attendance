<?php

namespace App\Services\Companies;

use App\Enums\UserRole;
use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Pages\InternalRegulation;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\CommitteeMandateResource;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\Law;
use Illuminate\Support\Facades\Storage;

class CompanySetup
{
    public function for(Company $company): array
    {
        $items = [];
        $languages = $company->enabled_languages ?? [];
        $letterheadReason = null;
        foreach ($languages as $language) {
            $letterhead = CompanyLetterhead::query()->where('company_id', $company->id)
                ->where('language_code', $language)->orderByDesc('version')->first();
            $asset = $letterhead?->header_mode === 'image' ? $letterhead?->header_image_path : $letterhead?->logo_path;
            if (! $letterhead || blank($letterhead->name) || $letterhead->needs_review
                || blank($asset) || ! Storage::disk('local')->exists($asset)) {
                $letterheadReason = __('messages.setup.letterhead_missing', ['language' => strtoupper($language)]);
                break;
            }
        }
        $items[] = $this->item('letterhead', $letterheadReason, CompanySettings::getUrl(panel: 'admin', tenant: $company));

        $branches = Branch::query()->where('company_id', $company->id)->get();
        $branchReason = $branches->isEmpty() ? __('messages.setup.branch_missing') : null;
        foreach ($branches as $branch) {
            if ($branch->jurisdiction === null) {
                $branchReason = __('messages.setup.jurisdiction_missing', ['branch' => $branch->name]);
                break;
            }
        }
        $items[] = $this->item('branches', $branchReason, BranchResource::getUrl(panel: 'admin', tenant: $company));

        $mandate = CommitteeMandate::query()->where('company_id', $company->id)
            ->inEffectOn(now('Asia/Baghdad')->toDateString())->orderByDesc('effective_from')->first();
        $committeeReason = $mandate === null ? __('messages.setup.mandate_missing') : null;
        if ($mandate !== null) {
            $members = $mandate->poolMembers()->with('user')->get();
            foreach ($branches as $branch) {
                foreach (['legal', 'hr', 'labor_union'] as $role) {
                    $eligible = $members->contains(fn ($member) => $member->role_type->value === $role
                        && ($member->branch_id === null || (int) $member->branch_id === (int) $branch->id)
                        && $member->user?->is_active && (int) $member->user->company_id === (int) $company->id
                        && in_array($member->user->primary_role, [UserRole::CommitteeMember, UserRole::HrStaff, UserRole::CompanyAdmin], true));
                    if (! $eligible) {
                        $committeeReason = __('messages.setup.approver_missing', [
                            'branch' => $branch->name, 'role' => match ($role) {
                                'legal' => __('messages.setup.role_legal'),
                                'hr' => __('messages.setup.role_hr'),
                                default => __('messages.setup.role_labor_union'),
                            },
                        ]);
                        break 2;
                    }
                }
            }
        }
        $items[] = $this->item('committee', $committeeReason, CommitteeMandateResource::getUrl(panel: 'admin', tenant: $company));

        $languageReason = in_array('ar', $languages, true) && in_array('en', $languages, true)
            ? null : __('messages.setup.languages_missing');
        $items[] = $this->item('languages', $languageReason, CompanySettings::getUrl(panel: 'admin', tenant: $company));

        $regulationReason = null;
        if (! in_array($company->internal_regulation_answer, ['yes', 'no'], true)) {
            $regulationReason = __('messages.setup.answer_missing');
        } elseif ($company->internal_regulation_answer === 'yes') {
            $uploaded = Law::query()->where('company_id', $company->id)->where('kind', 'internal_regulation')
                ->whereHas('importRuns', fn ($query) => $query->whereNotIn('state', ['failed', 'cancelled']))->exists();
            if (! $uploaded) {
                $regulationReason = __('messages.setup.upload_missing');
            }
        }
        $items[] = $this->item('regulation', $regulationReason, ($company->internal_regulation_answer === 'yes'
            ? InternalRegulation::getUrl(panel: 'admin', tenant: $company)
            : CompanySettings::getUrl(panel: 'admin', tenant: $company)));

        return $items;
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
