<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PartnershipService
{
    public function lawFirm(Company $company, int $partnerId, bool $attached, User $actor): void
    {
        abort_unless($actor->primary_role === UserRole::PlatformAdmin, 403);
        $query = DB::table('company_law_firm_partner')->where('company_id', $company->id)->where('law_firm_partner_id', $partnerId);
        $before = $query->exists();
        if ($attached && ! $before) {
            DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $partnerId]);
        } elseif (! $attached && $before) {
            $query->delete();
        } else {
            return;
        }
        app(AuditLogger::class)->log($attached ? 'partnership.attached' : 'partnership.detached', $company, ['partner_type' => 'law_firm', 'partner_id' => $partnerId, 'before' => $before, 'after' => $attached], $actor);
    }

    public function hrPartner(Company $company, int $partnerId, ?bool $fullCaseAccess, User $actor): void
    {
        abort_unless($actor->primary_role === UserRole::PlatformAdmin, 403);
        $query = DB::table('company_hr_partner')->where('company_id', $company->id)->where('hr_partner_id', $partnerId);
        $before = $query->first();
        if ($fullCaseAccess === null) {
            $query->delete();
        } elseif ($before) {
            $query->update(['full_case_access' => $fullCaseAccess]);
        } else {
            DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partnerId, 'full_case_access' => $fullCaseAccess]);
        }
        app(AuditLogger::class)->log($fullCaseAccess === null ? 'partnership.detached' : ($before ? 'partnership.updated' : 'partnership.attached'), $company, ['partner_type' => 'hr', 'partner_id' => $partnerId, 'before' => $before?->full_case_access, 'after' => $fullCaseAccess], $actor);
    }
}
