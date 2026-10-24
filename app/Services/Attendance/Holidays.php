<?php

namespace App\Services\Attendance;

use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Holidays
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Company $company, array $values, User $actor): Holiday
    {
        $this->authorize($company, $actor);
        $values = $this->validate($company, $values, false);

        return DB::transaction(function () use ($company, $values, $actor): Holiday {
            $holiday = Holiday::create(['company_id' => $company->id, 'name' => $values['name_en'], 'status' => 'draft']);
            $this->version($holiday, $values, 'draft', $actor, __('messages.holidays.manual_draft'));

            return $holiday->fresh();
        });
    }

    public function createFromSource(HolidaySource $source, User $actor): Holiday
    {
        $company = Company::findOrFail($source->company_id);
        $this->authorize($company, $actor);
        abort_unless($source->ai_status === 'done' && is_array($source->proposal), 409);
        $proposal = $source->proposal;
        $branches = Branch::query()->where('company_id', $company->id)
            ->whereIn('name', $proposal['branch_names'])->pluck('id')->all();

        return $this->create($company, [
            'name_en' => $proposal['name_en'], 'name_ar' => $proposal['name_ar'],
            'ranges' => $proposal['ranges'], 'legal_entities' => $proposal['legal_entities'],
            'branch_ids' => $branches, 'work_patterns' => $proposal['work_patterns'],
            'exceptions_note' => implode("\n", [...$proposal['exception_quotes'], ...$proposal['notes']]),
            'source_id' => $source->id,
        ], $actor);
    }

    public function approve(Holiday $holiday, array $values, User $actor, string $reason): HolidayVersion
    {
        return $this->change($holiday, $values, 'approved', 'draft', $actor, $reason);
    }

    public function edit(Holiday $holiday, array $values, User $actor, string $reason): HolidayVersion
    {
        return $this->change($holiday, $values, 'approved', 'approved', $actor, $reason);
    }

    public function withdraw(Holiday $holiday, User $actor, string $reason): HolidayVersion
    {
        return $this->change($holiday, [], 'withdrawn', 'approved', $actor, $reason);
    }

    public function reject(Holiday $holiday, User $actor, string $reason): HolidayVersion
    {
        return $this->change($holiday, [], 'rejected', 'draft', $actor, $reason);
    }

    private function change(Holiday $holiday, array $values, string $status, string $requiredStatus, User $actor, string $reason): HolidayVersion
    {
        $company = Company::findOrFail($holiday->company_id);
        $this->authorize($company, $actor);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.holidays.reason_required')]);
        }

        return DB::transaction(function () use ($holiday, $values, $status, $requiredStatus, $actor, $reason, $company): HolidayVersion {
            $holiday = Holiday::query()->whereKey($holiday->id)->lockForUpdate()->firstOrFail();
            abort_unless($holiday->status === $requiredStatus, 409);
            $before = $holiday->currentVersion;
            $merged = array_replace($before?->only([
                'name_en', 'name_ar', 'ranges', 'legal_entities', 'branch_ids', 'work_patterns', 'exceptions_note', 'source_id',
            ]) ?? [], $values);
            $merged = $this->validate($company, $merged, $status === 'approved');
            $version = $this->version($holiday, $merged, $status, $actor, $reason);
            if ($before?->status === 'approved' || $status === 'approved') {
                DB::afterCommit(fn () => $this->reprocess($company, $before, $version));
            }

            return $version;
        });
    }

    private function version(Holiday $holiday, array $values, string $status, User $actor, string $reason): HolidayVersion
    {
        $previous = $holiday->currentVersion;
        $version = HolidayVersion::create($values + [
            'company_id' => $holiday->company_id, 'holiday_id' => $holiday->id,
            'version' => ($previous?->version ?? 0) + 1, 'status' => $status,
            'decided_by' => $actor->id, 'reason' => $reason,
        ]);
        $holiday->update(['current_version_id' => $version->id, 'status' => $status, 'name' => $version->name_en]);
        $this->audit->log('attendance.holiday.'.$status, $holiday, [
            'holiday_id' => $holiday->id, 'before_version' => $previous?->id,
            'after_version' => $version->id, 'reason' => $reason,
        ], $actor);

        return $version;
    }

    private function validate(Company $company, array $values, bool $requireRanges): array
    {
        $defaults = [
            'name_en' => '', 'name_ar' => null, 'ranges' => [], 'legal_entities' => [],
            'branch_ids' => [], 'work_patterns' => [], 'exceptions_note' => null, 'source_id' => null,
        ];
        $values = array_replace($defaults, array_intersect_key($values, $defaults));
        if (! is_string($values['name_en']) || trim($values['name_en']) === ''
            || ($values['name_ar'] !== null && ! is_string($values['name_ar']))
            || ($values['exceptions_note'] !== null && ! is_string($values['exceptions_note']))
            || ($values['source_id'] !== null && ! ctype_digit((string) $values['source_id']))
            || ! is_array($values['ranges']) || ($requireRanges && $values['ranges'] === [])
            || ! is_array($values['legal_entities']) || ! is_array($values['branch_ids'])
            || ! is_array($values['work_patterns'])
            || array_filter($values['work_patterns'], fn ($pattern): bool => ! is_string($pattern)
                || ! in_array($pattern, ['office', 'shift', 'security'], true)) !== []) {
            throw ValidationException::withMessages(['holiday' => __('messages.holidays.invalid')]);
        }
        foreach ($values['ranges'] as $range) {
            if (! is_array($range) || ! isset($range['from'], $range['to'])
                || ! $this->dateValid($range['from']) || ! $this->dateValid($range['to'])
                || $range['from'] > $range['to']) {
                throw ValidationException::withMessages(['ranges' => __('messages.holidays.invalid')]);
            }
        }
        if (array_filter($values['legal_entities'], fn ($value): bool => ! is_string($value) || trim($value) === '') !== []
            || array_filter($values['branch_ids'], fn ($value): bool => ! ctype_digit((string) $value)
                || (int) $value < 1) !== []) {
            throw ValidationException::withMessages(['scope' => __('messages.holidays.invalid')]);
        }
        $branches = array_values(array_unique(array_map('intval', $values['branch_ids'])));
        if ($branches !== [] && Branch::query()->where('company_id', $company->id)->whereIn('id', $branches)->count() !== count($branches)) {
            throw ValidationException::withMessages(['branch_ids' => __('messages.holidays.invalid_branch')]);
        }
        sort($branches, SORT_NUMERIC);
        $values['branch_ids'] = $branches;
        if ($values['source_id'] !== null && ! HolidaySource::query()
            ->where('company_id', $company->id)->whereKey($values['source_id'])->exists()) {
            throw ValidationException::withMessages(['source_id' => __('messages.holidays.invalid')]);
        }

        return $values;
    }

    private function dateValid(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function authorize(Company $company, User $actor): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id
            && $actor->is_active && $actor->canAccessTenant($company), 403);
        Gate::forUser($actor)->authorize('update', $company);
    }

    private function reprocess(Company $company, ?HolidayVersion $old, HolidayVersion $new): void
    {
        $versions = array_values(array_filter([$old?->status === 'approved' ? $old : null,
            $new->status === 'approved' ? $new : null]));
        if ($versions === []) {
            return;
        }
        $dates = [];
        foreach ($versions as $version) {
            foreach ($version->ranges as $range) {
                for ($date = $range['from']; $date <= $range['to']; $date = CarbonImmutable::parse($date)->addDay()->toDateString()) {
                    $dates[$date] = true;
                }
            }
        }
        if ($dates === []) {
            return;
        }
        ksort($dates);
        $to = max(array_keys($dates));
        Employee::query()->where('company_id', $company->id)->orderBy('id')->chunkById(100, function ($employees) use ($versions, $dates, $company, $to): void {
            $histories = EmployeeVersion::query()->where('company_id', $company->id)
                ->whereIn('employee_id', $employees->pluck('id'))->whereDate('effective_from', '<=', $to)
                ->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
            $affectedByDate = [];
            foreach ($employees as $employee) {
                $history = $histories->get($employee->id, collect())->values();
                $values = $history->isEmpty() ? app(EmployeeHistory::class)->valuesFrom($employee)
                    : array_fill_keys(EmployeeHistory::FIELDS, null);
                $index = 0;
                foreach (array_keys($dates) as $date) {
                    while ($index < $history->count() && $history[$index]->effective_from->toDateString() <= $date) {
                        foreach ($history[$index]->changes as $field => $value) {
                            $values[$field] = $value;
                        }
                        $index++;
                    }
                    foreach ($versions as $version) {
                        if ($this->covers($version, $date) && HolidayCalendar::matches($version, $values)) {
                            $affectedByDate[$date][] = (int) $employee->id;

                            break;
                        }
                    }
                }
            }
            ksort($affectedByDate);
            $groupFrom = null;
            $groupTo = null;
            $groupIds = [];
            foreach ($affectedByDate as $date => $employeeIds) {
                if ($groupFrom !== null && ($employeeIds !== $groupIds
                    || CarbonImmutable::parse($groupTo)->addDay()->toDateString() !== $date)) {
                    $this->dispatchRange($company, $groupIds, $groupFrom, $groupTo);
                    $groupFrom = null;
                }
                $groupFrom ??= $date;
                $groupTo = $date;
                $groupIds = $employeeIds;
            }
            if ($groupFrom !== null) {
                $this->dispatchRange($company, $groupIds, $groupFrom, $groupTo);
            }
        });
    }

    private function dispatchRange(Company $company, array $employeeIds, string $from, string $to): void
    {
        $start = CarbonImmutable::parse($from)->subDay()->toDateString();
        $end = CarbonImmutable::parse($to)->addDay()->toDateString();
        $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
        if ($start <= $today) {
            AssessDaysJob::dispatch($company->id, ['employee_ids' => $employeeIds, 'from' => $start,
                'to' => min($end, $today)], 'holiday')->afterCommit();
        }
        ReconcileLeaveJob::dispatch($company->id, $employeeIds, 'holiday', $start, $end)->afterCommit();
    }

    private function covers(HolidayVersion $version, string $date): bool
    {
        foreach ($version->ranges as $range) {
            if ($date >= $range['from'] && $date <= $range['to']) {
                return true;
            }
        }

        return false;
    }
}
