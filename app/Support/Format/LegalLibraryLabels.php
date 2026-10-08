<?php

namespace App\Support\Format;

use Carbon\CarbonInterface;

class LegalLibraryLabels
{
    public static function category(string $value): string
    {
        return match ($value) {
            'labor' => __('Labor'), 'social_security' => __('Social security'),
            'regulation' => __('Internal regulation'), 'other' => __('Other'), default => __('Unknown category'),
        };
    }

    public static function jurisdiction(string $value): string
    {
        return match ($value) {
            'federal_iraq' => __('Federal Iraq'), 'kurdistan_region' => __('Kurdistan Region'),
            default => __('Unknown jurisdiction'),
        };
    }

    public static function unitStatus(string $value): string
    {
        return match ($value) {
            'unverified' => __('Unverified'), 'mismatch' => __('Needs review'),
            'verified_auto' => __('Verified automatically'), 'verified_human' => __('Verified by review'),
            'verified_text' => __('Verified from source text'), 'excluded' => __('Excluded'), default => __('Unknown status'),
        };
    }

    public static function runState(string $value): string
    {
        return match ($value) {
            'queued' => __('Queued'), 'reading' => __('Reading'),
            'ai_pending' => __('Waiting for AI reading'), 'splitting' => __('Splitting'),
            'completed' => __('Completed'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled'),
            default => __('Unknown status'),
        };
    }

    public static function pageStatus(string $value): string
    {
        return match ($value) {
            'pending' => __('Queued'), 'done' => __('Done'), 'failed' => __('Failed'),
            'unavailable' => __('Unavailable'), default => __('Unknown status'),
        };
    }

    public static function textMethod(string $value): string
    {
        return match ($value) {
            'text' => __('Text layer'), 'ocr' => 'OCR', default => __('Unknown method'),
        };
    }

    public static function mismatchReason(string $value): string
    {
        return match ($value) {
            'text_differs' => __('Text differs'), 'structure_differs' => __('Structure differs'),
            'one_reading_only' => __('Found in only one reading'),
            'duplicate_heading' => __('Duplicate heading — possibly amended text'),
            'page_failed' => __('Page reading failed'), 'illegible' => __('Illegible text'),
            default => __('Reading mismatch'),
        };
    }

    public static function statusColor(string $value): string
    {
        return match ($value) {
            'verified_auto', 'verified_human', 'verified_text', 'completed' => 'success',
            'mismatch', 'ai_pending' => 'warning', 'failed' => 'danger',
            'excluded', 'cancelled' => 'gray', default => 'gray',
        };
    }

    public static function counts(?array $counts): string
    {
        if (! $counts) {
            return '—';
        }

        $parts = [];
        foreach (['pages' => __('Pages'), 'units' => __('Units'), 'verified_auto' => __('Verified automatically'),
            'verified_human' => __('Verified by review'), 'verified_text' => __('Verified from source text'),
            'mismatch' => __('Needs review'), 'unverified' => __('Unverified'), 'excluded' => __('Excluded')] as $key => $label) {
            if (array_key_exists($key, $counts)) {
                $parts[] = Ui::number((int) $counts[$key]).' '.$label;
            }
        }

        return $parts ? implode(' — ', $parts) : '—';
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? Ui::date($date).__(', ')
            .Ui::number($date->copy()->setTimezone('Asia/Baghdad')->format('H:i')) : '—';
    }
}
