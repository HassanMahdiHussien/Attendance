<?php

namespace App\Services\Attendance\Reporting;

class TimeManagementDisplay
{
    public function summary(array $report): array
    {
        $totals = $report['totals'];
        $sum = fn (string $key): int => array_sum(array_map(fn (array $row): int => (int) $row[$key], $totals));
        $overtime = array_sum(array_map(fn (array $row): int => self::minutes($row['total_overtime']), $totals));
        $missing = [];
        foreach (['night', 'friday', 'holiday'] as $type) {
            foreach ($report[$type] as $row) {
                if ($row['note'] !== '') {
                    $missing[self::dayKey($row)] = true;
                }
            }
        }

        return [
            'employees' => count($totals), 'rows' => $this->dayCount($report), 'nights' => $sum('night_count'),
            'fridays' => $sum('friday_count'), 'holidays' => $sum('holiday_count'),
            'overtime' => self::hours($overtime),
            'missing_check_outs' => count($missing),
        ];
    }

    public function dayCount(array $report): int
    {
        $days = [];
        foreach (['night', 'friday', 'holiday'] as $type) {
            foreach ($report[$type] as $row) {
                $days[self::dayKey($row)] = true;
            }
        }

        return count($days);
    }

    public function totalRow(array $totals): array
    {
        $row = array_fill_keys(ExportData::TIME_MANAGEMENT_TOTAL_COLUMNS, '');
        $row['employee_code'] = __('messages.export_ui.total');
        foreach (['night_count', 'friday_count', 'holiday_count'] as $key) {
            $row[$key] = array_sum(array_column($totals, $key));
        }
        foreach (['night_total_time', 'friday_total_time', 'holiday_total_time', 'total_overtime'] as $key) {
            $row[$key] = self::hours(array_sum(array_map(fn (array $total): int => self::minutes($total[$key]), $totals)));
        }

        return $row;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private static function dayKey(array $row): string
    {
        return json_encode([$row['employee_code'], $row['date']], JSON_THROW_ON_ERROR);
    }

    private static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
