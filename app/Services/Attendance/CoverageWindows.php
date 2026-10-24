<?php

namespace App\Services\Attendance;

use App\Models\Attendance\CoverageInterval;
use App\Models\Company;
use Carbon\CarbonImmutable;

class CoverageWindows
{
    private array $windows = [];

    public function load(Company $company, string $from, string $to): void
    {
        $this->windows = [];
        $intervals = CoverageInterval::query()->where('company_id', $company->id)
            ->where('kind', 'declared')->where('is_confirmed', true)
            ->where('coverage_to', '>=', $from.' 00:00:00')
            ->where('coverage_from', '<=', $to.' 23:59:59')
            ->orderBy('branch_id')->orderBy('coverage_from')->get();
        foreach ($intervals as $interval) {
            $branchId = (int) $interval->branch_id;
            $start = $interval->coverage_from->format('Y-m-d H:i:s');
            $end = $interval->coverage_to->format('Y-m-d H:i:s');
            $last = count($this->windows[$branchId] ?? []) - 1;
            if ($last >= 0 && $start <= CarbonImmutable::parse($this->windows[$branchId][$last][1])->addSecond()->format('Y-m-d H:i:s')) {
                $this->windows[$branchId][$last][1] = max($end, $this->windows[$branchId][$last][1]);
            } else {
                $this->windows[$branchId][] = [$start, $end];
            }
        }
    }

    public function covers(?int $branchId, string $date, string $until): bool
    {
        return $this->coversWindow($branchId, $date.' 00:00:00', $until);
    }

    public function coversWindow(?int $branchId, string $start, string $until): bool
    {
        if ($branchId === null) {
            return false;
        }
        foreach ($this->windows[$branchId] ?? [] as [$from, $to]) {
            if ($from <= $start && $to >= $until) {
                return true;
            }
        }

        return false;
    }
}
