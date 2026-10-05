<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpiredAppealArchiver
{
    public function __construct(private CaseWorkflow $workflow) {}

    public function run(): void
    {
        foreach (Company::query()->where('is_active', true)->cursor() as $company) {
            app(TenantContext::class)->runForCompany($company, function (): void {
                $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
                foreach (DisciplinaryCase::query()->where('status', CaseStatus::AwaitingAppealWindow->value)->cursor() as $case) {
                    $appeal = DB::table('appeal_trackings')->where('case_id', $case->id)->first();
                    if ($appeal !== null && ! $appeal->appeal_filed && $today > $appeal->window_end) {
                        $this->workflow->transition($case, CaseStatus::ArchivedFinal, null, 'Appeal window expired');
                    }
                }
            });
        }
    }
}
