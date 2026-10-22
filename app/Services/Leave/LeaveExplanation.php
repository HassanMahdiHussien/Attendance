<?php

namespace App\Services\Leave;

class LeaveExplanation
{
    public function render(array $explanation): string
    {
        $code = $explanation['code'] ?? '';
        if (! in_array($code, ['leave_same_day', 'leave_credit_applied'], true)) {
            return __('messages.leave_ui.explanations.unknown');
        }
        $ltr = fn (mixed $value): string => "\u{2066}".(string) $value."\u{2069}";
        $minutes = (int) ($explanation['minutes'] ?? 0);

        return __('messages.leave_ui.explanations.'.$code, [
            'request' => $ltr($explanation['request'] ?? ''),
            'source_date' => $ltr($explanation['source_date'] ?? ''),
            'target_date' => $ltr($explanation['target_date'] ?? ''),
            'kind' => __('messages.leave_ui.target_kinds.'.($explanation['target_kind'] ?? 'unknown')),
            'minutes' => $ltr(sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60)),
        ]);
    }
}
