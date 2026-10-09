<?php

namespace App\Services\Dashboards;

use App\Models\User;
use App\Support\Access\CaseVisibility;
use App\Support\Format\Violation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Hotspots
{
    public const TYPE = "CASE WHEN cases.violation_type_id IS NOT NULL THEN CONCAT('vt:', cases.violation_type_id) ELSE CONCAT('txt:', LOWER(TRIM(REGEXP_REPLACE(cases.violation_type, '[[:space:]]+', ' ')))) END";

    private const KEYS = [
        'branch' => 'cases.branch_id',
        'department' => 'dashboard_employee.department',
        'location' => 'cases.location_id',
        'violation' => self::TYPE,
        'responsible' => "CASE WHEN cases.alleged_responsible_employee_id IS NOT NULL THEN CONCAT('e:', cases.alleged_responsible_employee_id) ELSE CONCAT('t:', LOWER(TRIM(REGEXP_REPLACE(COALESCE(cases.alleged_responsible_person_freetext, ''), '[[:space:]]+', ' ')))) END",
        'employee' => 'cases.employee_id',
    ];

    public function expression(string $group): string
    {
        abort_unless(isset(self::KEYS[$group]), 404);

        return self::KEYS[$group];
    }

    public function grouped(User $viewer, DashboardFilters $filters, string $group, bool $aggregate = false, ?int $company = null): array
    {
        $expression = $this->expression($group);
        $current = $this->base($viewer, $filters, $aggregate, $company, $group)
            ->where('cases.violation_time', '>=', $filters->start)->where('cases.violation_time', '<', $filters->end);
        $previous = $this->base($viewer, $filters, $aggregate, $company, $group)
            ->where('cases.violation_time', '>=', $filters->previousStart())->where('cases.violation_time', '<', $filters->start);
        if ($group === 'responsible') {
            // Cases with no named responsible person are not a hotspot of anyone.
            $current->whereRaw("{$expression} <> 't:'");
            $previous->whereRaw("{$expression} <> 't:'");
        }
        $previous = $previous->selectRaw("{$expression} as group_key, COUNT(*) as total")->groupByRaw($expression)->get()->pluck('total', 'group_key');
        $rows = $current->selectRaw("{$expression} as group_key, COUNT(*) as total")
            ->groupByRaw($expression)->orderByDesc('total')->limit(10)->get();
        $max = max(1, (int) ($rows->first()?->total ?? 0));
        $labels = $this->labels($group, $rows->pluck('group_key')->all());

        return $rows->map(fn ($row) => [
            'key' => (string) ($row->group_key ?? ''), 'label' => $labels[(string) ($row->group_key ?? '')] ?? ($group === 'location' ? __('messages.dashboard.location_unspecified') : __('messages.dashboard.unspecified')),
            'count' => (int) $row->total, 'previous' => (int) ($previous[$row->group_key ?? ''] ?? 0),
            'delta' => (int) $row->total - (int) ($previous[$row->group_key ?? ''] ?? 0),
            'width' => round(100 * $row->total / $max),
        ])->all();
    }

    public function threshold(User $viewer, DashboardFilters $filters, int $minimum, int $days): array
    {
        $start = now('Asia/Baghdad')->startOfDay()->subDays($days - 1);
        $end = now('Asia/Baghdad')->addDay()->startOfDay();
        $results = [];
        foreach (['department', 'location'] as $group) {
            $expression = $this->expression($group);
            $rows = $this->base($viewer, $filters, false, null, $group)
                ->where('cases.violation_time', '>=', $start)->where('cases.violation_time', '<', $end)
                ->whereRaw("{$expression} IS NOT NULL")
                ->when($group === 'department', fn ($query) => $query->whereRaw("{$expression} != ''"))
                ->selectRaw(self::TYPE." as violation_key, {$expression} as group_key, COUNT(*) as total")
                ->groupByRaw(self::TYPE.", {$expression}")->havingRaw('COUNT(*) >= ?', [$minimum])->orderByDesc('total')->get();
            $labels = $this->labels($group, $rows->pluck('group_key')->all());
            $violations = $this->labels('violation', $rows->pluck('violation_key')->all());
            foreach ($rows as $row) {
                $results[] = ['group' => $group, 'key' => (string) $row->group_key,
                    'violation' => (string) $row->violation_key,
                    'violation_label' => $violations[(string) $row->violation_key] ?? __('messages.dashboard.unspecified'),
                    'label' => $labels[(string) $row->group_key] ?? __('messages.dashboard.unspecified'),
                    'count' => (int) $row->total];
            }
        }

        return $results;
    }

    public function constrain(Builder $query, string $group, string $key): Builder
    {
        $expression = $this->expression($group);
        if ($group === 'department') {
            $query = $this->joinEmployee($query)->select('cases.*');
        }

        return $key === '' ? $query->whereRaw("{$expression} IS NULL") : $query->whereRaw("{$expression} = ?", [$key]);
    }

    private function base(User $viewer, DashboardFilters $filters, bool $aggregate, ?int $company, string $group): Builder
    {
        $query = ($aggregate ? CaseVisibility::aggregateFor($viewer) : CaseVisibility::for($viewer))
            ->when($company, fn ($query) => $query->where('cases.company_id', $company))
            ->when($filters->branch, fn ($query) => $query->where('cases.branch_id', $filters->branch))
            ->where('cases.status', '!=', 'draft');

        return $group === 'department' ? $this->joinEmployee($query) : $query;
    }

    private function joinEmployee(Builder $query): Builder
    {
        return $query->leftJoin('employees as dashboard_employee', fn ($join) => $join
            ->on('dashboard_employee.id', '=', 'cases.employee_id')
            ->on('dashboard_employee.company_id', '=', 'cases.company_id'));
    }

    private function labels(string $group, array $keys): array
    {
        if ($group === 'violation') {
            $ids = array_map(fn ($key) => (int) substr((string) $key, 3), array_filter($keys, fn ($key) => str_starts_with((string) $key, 'vt:')));
            $names = DB::table('violation_types')->whereIn('id', $ids)->pluck('names', 'id')->all();

            return collect($keys)->mapWithKeys(function ($key) use ($names): array {
                $key = (string) $key;
                $translations = str_starts_with($key, 'vt:') ? json_decode($names[(int) substr($key, 3)] ?? '{}', true) ?? [] : [];

                return [$key => (Violation::labelForKey($key, $translations) ?: __('messages.dashboard.unspecified'))];
            })->all();
        }
        if ($group === 'department') {
            return array_combine(array_map('strval', $keys), array_map(fn ($key) => (string) $key ?: __('messages.dashboard.unspecified'), $keys)) ?: [];
        }
        if ($group === 'responsible') {
            $names = DB::table('employees')->whereIn('id', array_map(fn ($key) => substr((string) $key, 2),
                array_filter($keys, fn ($key) => str_starts_with((string) $key, 'e:'))))->pluck('name', 'id')->all();

            return collect($keys)->mapWithKeys(fn ($key) => [(string) $key => str_starts_with((string) $key, 'e:')
                ? ($names[substr((string) $key, 2)] ?? __('messages.dashboard.unspecified')) : (substr((string) $key, 2) ?: __('messages.dashboard.unspecified'))])->all();
        }
        $table = match ($group) {
            'branch' => 'branches', 'location' => 'locations', 'employee' => 'employees',
        };

        return DB::table($table)->whereIn('id', array_filter($keys, fn ($key) => $key !== null && $key !== ''))
            ->pluck('name', 'id')->all();
    }
}
