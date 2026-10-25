<?php

namespace App\Services\Attendance\Reporting;

class ReportTiles
{
    public const KEYS = [
        'active_employees', 'office_employees_assessed', 'assessed_working_days', 'present', 'absent', 'missing_punch', 'shift_unresolved', 'pending',
        'holiday', 'holiday_worked', 'rest_day_worked', 'holiday_work_minutes', 'rest_day_work_minutes',
        'late', 'early', 'short_hours_alerts', 'balance', 'reconciled', 'remaining',
        'leave_status:pending', 'leave_status:draft', 'leave_status:rejected',
        'credit_created', 'credit_used', 'credit_available', 'data_issues', 'restaurant', 'without_work_pattern',
        'hr_confirmed_absence', 'absence_closed_by_request',
        'overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now',
        'open_cases', 'waiting_manager', 'waiting_approver', 'waiting_hr',
    ];

    public static function fromTotals(array $totals): array
    {
        $minutes = $totals['minutes'];
        $sum = fn (string $measure): int => array_sum(array_column($minutes, $measure));

        $tiles = [
            'active_employees' => $totals['active_employees'],
            'office_employees_assessed' => $totals['office_employees_assessed'],
            'assessed_working_days' => $totals['assessed_working_days'],
            'present' => $totals['days']['present'],
            'absent' => $totals['days']['absent'],
            'missing_punch' => $totals['days']['missing_punch'],
            'shift_unresolved' => $totals['days']['shift_unresolved'],
            'pending' => $totals['days']['pending'],
            'holiday' => $totals['days']['holiday'] ?? 0, 'holiday_worked' => $totals['days']['holiday_worked'] ?? 0,
            'rest_day_worked' => $totals['days']['rest_day_worked'] ?? 0,
            'holiday_work_minutes' => $totals['holiday_work_minutes'] ?? 0,
            'rest_day_work_minutes' => $totals['rest_day_work_minutes'] ?? 0,
            'late' => $minutes['late']['gross'],
            'early' => $minutes['early']['gross'],
            'short_hours_alerts' => $totals['short_hours_alerts'],
            'balance' => $sum('gross'),
            'reconciled' => $sum('reconciled_by_leave') + $sum('reconciled_by_credit'),
            'remaining' => $sum('remaining'),
            'leave_status:pending' => $totals['pending_leave_requests']['pending'] ?? 0,
            'leave_status:draft' => $totals['pending_leave_requests']['draft'] ?? 0,
            'leave_status:rejected' => $totals['pending_leave_requests']['rejected'] ?? 0,
            'credit_created' => $totals['credits']['created'],
            'credit_used' => $totals['credits']['used_in_period'],
            'credit_available' => $totals['credits']['available_at_period_end'],
            'data_issues' => array_sum($totals['issues']['attributable']),
            'restaurant' => $totals['meals']['employee'],
            'without_work_pattern' => $totals['without_work_pattern'],
            'hr_confirmed_absence' => $totals['flags']['hr_confirmed_absence'],
            'absence_closed_by_request' => $totals['flags']['absence_closed_by_request'],
        ];
        foreach ($totals['leave'] as $group => $minutes) {
            $tiles['leave:'.$group] = $minutes;
        }
        foreach ($totals['issues']['attributable'] as $type => $count) {
            $tiles['issue_type:'.$type] = $count;
        }

        return $tiles;
    }

    public static function allowed(string $tile): bool
    {
        return in_array($tile, self::KEYS, true)
            || (strlen($tile) <= 255 && preg_match('/\Aleave:[^|\r\n]+\|(approved|pending|draft|rejected|cancelled)\z/u', $tile) === 1)
            || (str_starts_with($tile, 'issue_type:') && preg_match('/\Aissue_type:[a-z_]+\z/', $tile) === 1);
    }

    public static function overtime(array $totals): array
    {
        return [
            'overtime_minutes' => $totals['overtime_minutes'],
            'entitled_employees' => $totals['entitled_employees'],
            'entitlements_available_now' => $totals['entitlements_available_now'],
            'entitlements_overdue_now' => $totals['entitlements_overdue_now'],
        ];
    }

    public static function isMinutes(string $tile): bool
    {
        return str_starts_with($tile, 'leave:') || in_array($tile, ['late', 'early', 'balance', 'reconciled', 'remaining',
            'credit_created', 'credit_used', 'credit_available', 'holiday_work_minutes', 'rest_day_work_minutes', 'overtime_minutes'], true);
    }

    public static function isDays(string $tile): bool
    {
        return in_array($tile, ['entitlements_available_now', 'entitlements_overdue_now'], true);
    }

    public static function label(string $tile): string
    {
        $label = match (true) {
            str_starts_with($tile, 'leave:') => substr($tile, 6, strrpos($tile, '|') - 6)
                .' · '.__('messages.leave_ui.statuses.'.substr($tile, strrpos($tile, '|') + 1)),
            str_starts_with($tile, 'issue_type:') => __('messages.attendance_ui.issue_types.'.substr($tile, 11)),
            default => __('messages.reporting_ui.tiles.'.$tile),
        };

        return app()->getLocale() === 'en' ? ucfirst($label) : $label;
    }
}
