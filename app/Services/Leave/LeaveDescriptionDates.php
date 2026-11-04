<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

class LeaveDescriptionDates
{
    public function dates(string $text, ?string $startDate): array
    {
        $text = $this->digits($text);
        $limit = $startDate === null ? null : CarbonImmutable::parse($startDate)->addDay();
        $dates = [];
        $inferred = false;
        preg_match_all('/(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})(?!\d)/u', $text, $full, PREG_SET_ORDER);
        foreach ($full as $match) {
            $year = (int) $match[3];
            $year = $year < 100 ? 2000 + $year : $year;
            if (checkdate((int) $match[2], (int) $match[1], $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]);
            }
        }
        preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/u', $text, $iso, PREG_SET_ORDER);
        foreach ($iso as $match) {
            if (checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                $dates[] = $match[0];
            }
        }
        preg_match_all('/(?<![\d.\/-])(\d{1,2})[.\/-](\d{1,2})(?![\d.\/-])/u', $text, $short, PREG_SET_ORDER);
        foreach ($short as $match) {
            if ($limit === null) {
                continue;
            }
            for ($year = $limit->year; $year >= $limit->year - 4; $year--) {
                if (checkdate((int) $match[2], (int) $match[1], $year)) {
                    $date = sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]);
                    if ($date <= $limit->toDateString()) {
                        $dates[] = $date;
                        $inferred = true;
                        break;
                    }
                }
            }
        }

        return ['dates' => array_values(array_unique($dates)), 'inferred_year' => $inferred];
    }

    public function period(string $text, ?string $startDate): ?array
    {
        $text = $this->digits($text);
        $names = [];
        foreach (config('leave_description_lexicon.months') as $month => $aliases) {
            foreach ($aliases as $alias) {
                $names[mb_strtolower($alias)] = (int) $month;
            }
        }
        uksort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/(?<!\pL)('.implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), array_keys($names))).')(?!\pL)(?:\s+(20\d{2}))?/iu';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = ['month' => $names[mb_strtolower($match[1][0])],
                'year' => isset($match[2]) && $match[2][0] !== '' ? (int) $match[2][0] : null,
                'end' => $match[0][1] + strlen($match[0][0]), 'start' => $match[0][1]];
        }
        for ($index = count($tokens) - 2; $index >= 0; $index--) {
            $between = substr($text, $tokens[$index]['end'], $tokens[$index + 1]['start'] - $tokens[$index]['end']);
            if ($tokens[$index]['year'] === null && $tokens[$index + 1]['year'] !== null
                && preg_replace('/(?:\s|,|&|and|\x{0648})+/iu', '', $between) === '') {
                $tokens[$index]['year'] = $tokens[$index + 1]['year'];
            }
        }
        preg_match_all('/(?<![\d.\/-])(0?[1-9]|1[0-2])[.\/-](20\d{2})(?!\d)/u', $text, $numeric, PREG_SET_ORDER);
        $ranges = [];
        $inferred = false;
        foreach ($tokens as $index => $token) {
            $year = $token['year'];
            if ($year === null && $startDate !== null) {
                $limit = CarbonImmutable::parse($startDate);
                $year = $limit->month >= $token['month'] ? $limit->year : $limit->year - 1;
                $inferred = true;
            }
            if ($year !== null) {
                $first = CarbonImmutable::create($year, $token['month'], 1, 0, 0, 0, 'Asia/Baghdad');
                $ranges[] = [$first->toDateString(), $first->endOfMonth()->toDateString()];
            }
        }
        foreach ($numeric as $match) {
            $first = CarbonImmutable::create((int) $match[2], (int) $match[1], 1, 0, 0, 0, 'Asia/Baghdad');
            $ranges[] = [$first->toDateString(), $first->endOfMonth()->toDateString()];
        }
        if ($ranges === []) {
            $dates = $this->dates($text, $startDate);
            if ($dates['dates'] === []) {
                return null;
            }

            return ['from' => min($dates['dates']), 'to' => max($dates['dates']),
                'inferred_year' => $dates['inferred_year']];
        }

        return ['from' => min(array_column($ranges, 0)), 'to' => max(array_column($ranges, 1)),
            'inferred_year' => $inferred];
    }

    public function bareDate(string $text): bool
    {
        return preg_match('/^\d{1,2}[.\/-]\d{1,2}(?:[.\/-](?:\d{2}|\d{4}))?$/u', trim($this->digits($text))) === 1;
    }

    public function digits(string $text): string
    {
        return str_replace(config('leave_description_lexicon.digits'),
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    }
}
