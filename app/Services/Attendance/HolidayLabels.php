<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Holiday;

class HolidayLabels
{
    public function forIds(int $companyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Holiday::query()->with('currentVersion')->where('company_id', $companyId)
            ->whereIn('id', array_unique($ids))->get()->mapWithKeys(fn (Holiday $holiday): array => [
                $holiday->id => app()->getLocale() === 'ar'
                    ? ($holiday->currentVersion?->name_ar ?: $holiday->name)
                    : $holiday->name,
            ])->all();
    }
}
