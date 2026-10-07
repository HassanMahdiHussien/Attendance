<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\CompanyResource;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Support\Access\CaseVisibility;
use Filament\Widgets\Widget;

class CompaniesOverview extends Widget
{
    protected string $view = 'filament.platform.widgets.companies-overview';

    // One grouped query; load with the page instead of on scroll.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->primary_role === UserRole::PlatformAdmin;
    }

    public function companies(): array
    {
        abort_unless(static::canView(), 403);
        $today = now('Asia/Baghdad')->startOfDay()->toDateString();
        $caseCounts = CaseVisibility::for(auth()->user())->selectRaw('company_id,
            SUM(status != ? AND status != ?) as open_cases,
            SUM(status != ? AND status != ? AND defense_letter_issued_at IS NOT NULL AND decision_issued_at IS NULL AND closed_at IS NULL AND DATE(defense_letter_issued_at) < DATE_SUB(?, INTERVAL ? DAY)) as past_due,
            SUM(legal_review_requested_at IS NOT NULL AND legal_review_completed_at IS NULL AND legal_review_cancelled_at IS NULL) as pending_consultations',
            ['draft', 'archived_final', 'draft', 'archived_final', $today, (int) config('displanery.deadlines.procedure_days')])
            ->groupBy('company_id');
        $activities = CaseEvent::query()->selectRaw('company_id, MAX(created_at) as last_activity')->groupBy('company_id');

        return Company::query()->leftJoinSub($caseCounts, 'dashboard_cases', 'dashboard_cases.company_id', '=', 'companies.id')
            ->leftJoinSub($activities, 'dashboard_activity', 'dashboard_activity.company_id', '=', 'companies.id')
            ->select('companies.*', 'dashboard_cases.open_cases', 'dashboard_cases.past_due', 'dashboard_cases.pending_consultations', 'dashboard_activity.last_activity')
            ->orderBy('companies.name')->get()->map(fn (Company $company) => [
                'name' => $company->name, 'active' => $company->is_active,
                'subscription' => $company->subscription_status->value,
                'open' => (int) $company->open_cases, 'past_due' => (int) $company->past_due,
                'consultations' => (int) $company->pending_consultations,
                'last_activity' => $company->last_activity,
                'url' => CompanyResource::getUrl('edit', ['record' => $company->id]),
            ])->all();
    }
}
