<?php

namespace App\Services\Dashboards;

use App\Enums\CaseStatus;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\Cases\Deadlines;
use App\Support\Access\CaseVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CaseMetrics
{
    private const COMPLETED = "CASE WHEN cases.outcome = 'recommendation_only' THEN cases.closed_at ELSE cases.decision_issued_at END";

    private const DAYS = 'DATEDIFF(DATE('.self::COMPLETED.'), DATE(cases.defense_letter_issued_at))';

    public function __construct(private Deadlines $deadlines, private Hotspots $hotspots) {}

    public function counts(User $viewer, DashboardFilters $filters, bool $aggregate = false, ?int $company = null): array
    {
        $keys = ['open', 'closed_month', 'recommendation_only', 'warning', 'termination', 'within15_numerator', 'within15_denominator'];
        $counts = [];
        foreach ($keys as $key) {
            $counts[$key] = $this->metricQuery($viewer, $filters, $key, $aggregate, $company)->count();
        }
        $average = $this->base($viewer, $filters, $aggregate, $company)
            ->whereRaw(self::COMPLETED.' >= ?', [$filters->start])
            ->whereRaw(self::COMPLETED.' < ?', [$filters->end])
            ->whereNotNull('cases.defense_letter_issued_at')->selectRaw('AVG('.self::DAYS.') as average_days')->first()?->average_days;
        $counts['average_days'] = $average === null ? null : round((float) $average, 1);
        $counts['within15_percent'] = $counts['within15_denominator'] > 0
            ? round(100 * $counts['within15_numerator'] / $counts['within15_denominator']) : null;

        return $counts;
    }

    public function metricQuery(User $viewer, DashboardFilters $filters, string $metric, bool $aggregate = false, ?int $company = null): Builder
    {
        $query = $this->base($viewer, $filters, $aggregate, $company);
        if ($metric === 'open') {
            return $query->where('cases.status', '!=', CaseStatus::ArchivedFinal->value);
        }
        if ($metric === 'closed_month') {
            $month = CarbonImmutable::now('Asia/Baghdad')->startOfMonth();

            return $query->where('cases.status', CaseStatus::ArchivedFinal->value)
                ->where('cases.closed_at', '>=', $month)->where('cases.closed_at', '<', $month->addMonth());
        }
        if (in_array($metric, ['recommendation_only', 'warning', 'termination', 'within15_numerator', 'within15_denominator'], true)) {
            $query->whereRaw(self::COMPLETED.' >= ?', [$filters->start])
                ->whereRaw(self::COMPLETED.' < ?', [$filters->end]);
            if (in_array($metric, ['within15_numerator', 'within15_denominator'], true)) {
                $query->whereNotNull('cases.defense_letter_issued_at');
                if ($metric === 'within15_numerator') {
                    $query->whereRaw(self::DAYS.' <= ?', [(int) config('displanery.deadlines.procedure_days')]);
                }
            } else {
                $query->where('cases.outcome', $metric);
            }

            return $query;
        }
        abort(404);
    }

    public function hotspotQuery(User $viewer, DashboardFilters $filters, string $group, string $key): Builder
    {
        return $this->hotspots->constrain($this->base($viewer, $filters, false, null)
            ->where('cases.violation_time', '>=', $filters->start)
            ->where('cases.violation_time', '<', $filters->end), $group, $key);
    }

    public function thresholdQuery(User $viewer, DashboardFilters $filters, string $group, string $key, string $violation, int $days): Builder
    {
        abort_unless(in_array($group, ['department', 'location'], true), 404);
        $start = CarbonImmutable::now('Asia/Baghdad')->startOfDay()->subDays($days - 1);
        $end = CarbonImmutable::now('Asia/Baghdad')->addDay()->startOfDay();

        return $this->hotspots->constrain($this->base($viewer, $filters, false, null)
            ->where('cases.violation_time', '>=', $start)->where('cases.violation_time', '<', $end)
            ->whereRaw(Hotspots::TYPE.' = ?', [$violation]), $group, $key);
    }

    public function hotspots(User $viewer, DashboardFilters $filters, bool $aggregate = false, ?int $company = null): array
    {
        $groups = $aggregate ? ['branch', 'department', 'location', 'violation'] : ['branch', 'department', 'location', 'violation', 'responsible', 'employee'];
        $result = [];
        foreach ($groups as $group) {
            $result[$group] = $this->hotspots->grouped($viewer, $filters, $group, $aggregate, $company);
        }

        return $result;
    }

    public function thresholds(User $viewer, DashboardFilters $filters, int $minimum, int $days): array
    {
        return $this->hotspots->threshold($viewer, $filters, $minimum, $days);
    }

    public function reviewerSummary(User $viewer): array
    {
        $base = CaseVisibility::aggregateFor($viewer);
        $month = CarbonImmutable::now('Asia/Baghdad')->startOfMonth();
        $waiting = fn () => (clone $base)->whereNotNull('cases.legal_review_requested_at')
            ->whereNull('cases.legal_review_completed_at')->whereNull('cases.legal_review_cancelled_at');
        $oldestAt = $waiting()->selectRaw('MIN(cases.legal_review_requested_at) as oldest_at')->first()?->oldest_at;
        $oldestDefense = $oldestAt ? $waiting()->where('cases.legal_review_requested_at', $oldestAt)
            ->selectRaw('MIN(cases.defense_letter_issued_at) as defense_at')->first()?->defense_at : null;
        $visibleOldest = $oldestAt ? CaseVisibility::for($viewer)->where('cases.legal_review_requested_at', $oldestAt)
            ->whereNull('cases.legal_review_completed_at')->whereNull('cases.legal_review_cancelled_at')->first() : null;

        return ['waiting' => $waiting()->count(),
            'completed_month' => (clone $base)->where('cases.legal_review_completed_at', '>=', $month)
                ->where('cases.legal_review_completed_at', '<', $month->addMonth())->count(),
            'oldest' => $oldestAt ? ['requested_at' => $oldestAt,
                'due' => $oldestDefense ? $this->deadlines->procedureDue(new DisciplinaryCase(['defense_letter_issued_at' => $oldestDefense])) : null,
                'url' => $visibleOldest ? '/cases/'.$visibleOldest->id : null] : null];
    }

    public function alerts(User $viewer, DashboardFilters $filters): array
    {
        $now = CarbonImmutable::now('Asia/Baghdad');
        $candidate = $this->base($viewer, $filters, false, null)
            ->where(fn ($query) => $query->where(fn ($procedure) => $procedure->whereNotNull('cases.defense_letter_issued_at')
                ->where('cases.defense_letter_issued_at', '>=', $now->subDays(30))
                ->whereNull('cases.decision_issued_at')->where('cases.status', '!=', CaseStatus::ArchivedFinal->value))
                ->orWhere(fn ($appeal) => $appeal->where('cases.status', CaseStatus::AwaitingAppealWindow->value)
                    ->where('cases.decision_issued_at', '>=', $now->subDays(32)))
                ->orWhere(fn ($consultation) => $consultation->whereNotNull('cases.legal_review_requested_at')
                    ->whereNull('cases.legal_review_completed_at')->whereNull('cases.legal_review_cancelled_at')))
            ->with('employee')->get();
        $alerts = [];
        foreach ($candidate as $case) {
            $add = function (string $type, ?CarbonImmutable $due) use (&$alerts, $case, $now): void {
                $seconds = $due ? $due->endOfDay()->getTimestamp() - $now->getTimestamp() : null;
                $alerts[] = ['type' => $type, 'case' => $case, 'due' => $due,
                    'remaining_hours' => $seconds === null ? null : ($seconds < 0 ? (int) floor($seconds / 3600) : (int) ceil($seconds / 3600)),
                    'waiting_days' => $type === 'consultation_pending'
                        ? (int) CarbonImmutable::parse($case->legal_review_requested_at)->setTimezone('Asia/Baghdad')->startOfDay()->diffInDays($now->startOfDay()) : null];
            };
            if ($case->decision_issued_at === null && $case->status !== CaseStatus::ArchivedFinal) {
                $status = $this->deadlines->procedureStatus($case, $now);
                if (in_array($status, ['approaching', 'overdue'], true)) {
                    $add('procedure_'.$status, $this->deadlines->procedureDue($case));
                }
                if ($case->status === CaseStatus::AwaitingEmployeeResponse) {
                    $response = $this->deadlines->responseStatus($case, $now);
                    if (in_array($response, ['approaching', 'missed'], true)) {
                        $add('response_'.$response, $this->deadlines->responseDue($case));
                    }
                }
            }
            if ($case->legal_review_requested_at && ! $case->legal_review_completed_at && ! $case->legal_review_cancelled_at) {
                $add('consultation_pending', null);
            }
            if ($case->status === CaseStatus::AwaitingAppealWindow && $this->deadlines->appealStatus($case, $now) === 'approaching') {
                $add('appeal_approaching', $this->deadlines->appealEnd($case));
            }
        }

        // Most urgent first: missed deadlines (most overdue first), then the nearest due, then waiting consultations.
        usort($alerts, fn (array $a, array $b): int => [$a['remaining_hours'] === null, $a['remaining_hours'] ?? 0, -($a['waiting_days'] ?? 0)]
            <=> [$b['remaining_hours'] === null, $b['remaining_hours'] ?? 0, -($b['waiting_days'] ?? 0)]);

        return $alerts;
    }

    private function base(User $viewer, DashboardFilters $filters, bool $aggregate, ?int $company): Builder
    {
        return ($aggregate ? CaseVisibility::aggregateFor($viewer) : CaseVisibility::for($viewer))
            ->when($company, fn ($query) => $query->where('cases.company_id', $company))
            ->when($filters->branch, fn ($query) => $query->where('cases.branch_id', $filters->branch))
            ->where('cases.status', '!=', CaseStatus::Draft->value);
    }
}
