<?php

namespace App\Services\Home;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cases\NextAction;
use App\Services\Cases\PoolEligibility;
use App\Services\Companies\CompanySetup;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use App\Support\Format\Violation;
use Illuminate\Support\Facades\DB;

class MyWork
{
    public function __construct(private NextAction $nextAction, private CaseMetrics $metrics) {}

    public function for(User $user): array
    {
        return match ($user->primary_role) {
            UserRole::CompanyAdmin, UserRole::HrStaff => $this->hr($user),
            UserRole::CommitteeMember => $this->committee($user),
            UserRole::ExternalLegalReviewer => $this->reviewer($user),
            UserRole::Reporter, UserRole::DeptManager => $this->reporter($user),
            UserRole::HrPartnerStaff => $this->partner($user),
            default => ['role' => 'platform'],
        };
    }

    private function hr(User $user): array
    {
        $filters = DashboardFilters::fromInput($user, ['period' => '6']);
        $counts = $this->metrics->counts($user, $filters);
        $cases = CaseVisibility::for($user)->where('cases.status', '!=', CaseStatus::ArchivedFinal->value)
            ->with(['employee', 'documents.approvals', 'signatures', 'regulationComparisons', 'draftFailures'])->get();
        $mapped = $this->nextAction->forMany($cases, $user);
        $actions = $cases->map(fn ($case) => ['case' => $case, 'action' => $mapped->get($case->id)])
            ->filter(fn (array $row) => in_array($row['action']?->actor, ['hr', 'committee'], true));
        $newReports = ViolationReportVisibility::inbox($user)->latest()->get();
        foreach ($newReports as $report) {
            $actions->push(['report' => $report, 'label' => __('messages.home.new_report'), 'summary' => Violation::name($report), 'url' => '/reports/inbox', 'tone' => 'indigo']);
        }
        foreach (EmailDraftVisibility::inbox($user)->latest()->get() as $draft) {
            $actions->push(['email' => $draft, 'label' => __('messages.home.new_email'), 'summary' => $draft->subject, 'url' => '/reports/inbox', 'tone' => 'indigo']);
        }
        $actions = $actions->sortBy(fn (array $row) => [
            isset($row['report']) ? 0 : 1,
            match (($row['action'] ?? null)?->deadlineAlert ?? $row['tone'] ?? 'indigo') {
                'red' => 0, 'amber' => 1, 'indigo' => 2, default => 3,
            }, ($row['action'] ?? null)?->due?->timestamp ?? PHP_INT_MAX,
        ])->values();
        $events = CaseEvent::query()->whereIn('case_id', CaseVisibility::for($user)->select('cases.id'))
            ->with(['disciplinaryCase:id,case_number,company_id', 'actorUser:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();
        $events = app(ActivityFeed::class)->merge($events)->take(10);
        $setup = app(CompanySetup::class);
        $incomplete = $user->company_id !== null && ! $setup->complete($user->company);

        return ['role' => 'hr', 'counts' => $counts, 'new_reports_count' => $newReports->count(), 'actions' => $actions,
            'setup_incomplete' => $incomplete,
            'setup' => $incomplete && $user->primary_role === UserRole::CompanyAdmin
                ? array_values(array_filter($setup->for($user->company), fn (array $item): bool => ! $item['done'])) : [],
            'setup_admin' => $user->primary_role === UserRole::CompanyAdmin,
            'hotspots' => $this->metrics->thresholds($user, $filters,
                (int) ($user->company?->hotspot_min_cases ?? 3), (int) ($user->company?->hotspot_window_days ?? 90)),
            'events' => $events];
    }

    private function committee(User $user): array
    {
        $documents = app(PoolEligibility::class)->pendingFor($user);
        $cases = CaseVisibility::for($user)->whereExists(fn ($query) => $query->selectRaw('1')
            ->from('committee_session_attendances as a')->join('committee_pool_members as p', 'p.id', '=', 'a.committee_pool_member_id')
            ->whereColumn('a.case_id', 'cases.id')->where('p.user_id', $user->id))
            ->with('employee')->latest()->limit(10)->get();

        return ['role' => 'committee', 'documents' => $documents, 'cases' => $cases];
    }

    private function reviewer(User $user): array
    {
        $cases = CaseVisibility::for($user)->whereNotNull('legal_review_requested_at')
            ->whereNull('legal_review_completed_at')->whereNull('legal_review_cancelled_at')
            ->orderBy('legal_review_requested_at')->get();
        $employees = Employee::withoutGlobalScopes()->whereIn('id', $cases->pluck('employee_id'))->get()->keyBy('id');
        foreach ($cases as $case) {
            $case->setRelation('employee', $employees->get($case->employee_id));
        }

        return ['role' => 'reviewer', 'cases' => $cases];
    }

    private function reporter(User $user): array
    {
        return ['role' => 'reporter',
            'reports' => ViolationReportVisibility::for($user)->with(['employees', 'employee', 'location'])->latest()->limit(5)->get(),
            'emails' => EmailDraftVisibility::for($user)->latest()->get(['id', 'status', 'company_id', 'created_at'])];
    }

    private function partner(User $user): array
    {
        $links = DB::table('company_hr_partner')->where('hr_partner_id', $user->hr_partner_id)->get();
        $companies = Company::query()->whereIn('id', $links->pluck('company_id'))->get()->keyBy('id');
        $filters = DashboardFilters::fromInput($user, ['period' => '6']);
        $overview = [];
        foreach ($links as $link) {
            $company = $companies->get($link->company_id);
            if ($company) {
                $overview[] = ['company' => $company, 'full_access' => (bool) $link->full_case_access,
                    'counts' => $this->metrics->counts($user, $filters, true, $company->id),
                    'hotspots' => $this->metrics->hotspots($user, $filters, true, $company->id)];
            }
        }

        return ['role' => 'partner', 'overview' => $overview];
    }
}
