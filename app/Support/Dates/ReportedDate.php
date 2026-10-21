<?php

namespace App\Support\Dates;

use Carbon\CarbonImmutable;

class ReportedDate
{
    public static function parse(string $text, CarbonImmutable $sentAt): ?CarbonImmutable
    {
        $sentAt = $sentAt->setTimezone('Asia/Baghdad');
        $text = mb_strtolower(strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $date = null;
        if (preg_match('/(?<!\d)(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?!\d)/u', $text, $match)) {
            $date = self::calendar((int) $match[1], (int) $match[2], (int) $match[3]);
        } elseif (preg_match('/(?<!\d)(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?!\d)/u', $text, $match)) {
            $date = self::calendar((int) $match[3], (int) $match[2], (int) $match[1]);
        } else {
            $months = [
                1 => ['january', 'jan', 'كانون الثاني', 'يناير'], 2 => ['february', 'feb', 'شباط', 'فبراير'],
                3 => ['march', 'mar', 'آذار', 'اذار', 'مارس'], 4 => ['april', 'apr', 'نيسان', 'أبريل', 'ابريل'],
                5 => ['may', 'أيار', 'ايار', 'مايو'], 6 => ['june', 'jun', 'حزيران', 'يونيو'],
                7 => ['july', 'jul', 'تموز', 'يوليو'], 8 => ['august', 'aug', 'آب', 'اب', 'أغسطس', 'اغسطس'],
                9 => ['september', 'sep', 'sept', 'أيلول', 'ايلول', 'سبتمبر'],
                10 => ['october', 'oct', 'تشرين الأول', 'تشرين الاول', 'أكتوبر', 'اكتوبر'],
                11 => ['november', 'nov', 'تشرين الثاني', 'نوفمبر'],
                12 => ['december', 'dec', 'كانون الأول', 'كانون الاول', 'ديسمبر'],
            ];
            foreach ($months as $month => $names) {
                $names = array_map(fn (string $name): string => preg_quote($name, '~'), $names);
                $pattern = implode('|', $names);
                if (preg_match("~(?<!\\d)(\\d{1,2})(?:st|nd|rd|th)?[\\s,.-]+(?:{$pattern})[\\s,.-]+(\\d{4})(?!\\d)~u", $text, $match)
                    || preg_match("~(?:{$pattern})[\\s,.-]+(\\d{1,2})(?:st|nd|rd|th)?[\\s,.-]+(\\d{4})(?!\\d)~u", $text, $match)) {
                    $date = self::calendar((int) $match[2], $month, (int) $match[1]);
                    break;
                }
            }
            if ($date === null) {
                // Whole words only: «الخامس» (the fifth) contains «امس» and must not read as yesterday.
                $daysBack = match (true) {
                    preg_match('/(?<!\p{L})(?:day before yesterday|أول أمس|اول امس)(?!\p{L})/u', $text) === 1 => 2,
                    preg_match('/(?<!\p{L})(?:yesterday|أمس|امس|البارحة|البارحه)(?!\p{L})/u', $text) === 1 => 1,
                    preg_match('/(?<!\p{L})(?:today|اليوم)(?!\p{L})/u', $text) === 1 => 0,
                    default => null,
                };
                if ($daysBack !== null) {
                    $date = $sentAt->startOfDay()->subDays($daysBack);
                } else {
                    $weekdays = ['sunday' => 0, 'الأحد' => 0, 'الاحد' => 0, 'monday' => 1, 'الاثنين' => 1,
                        'tuesday' => 2, 'الثلاثاء' => 2, 'wednesday' => 3, 'الأربعاء' => 3, 'الاربعاء' => 3,
                        'thursday' => 4, 'الخميس' => 4, 'friday' => 5, 'الجمعة' => 5,
                        'saturday' => 6, 'السبت' => 6];
                    foreach ($weekdays as $word => $weekday) {
                        if (mb_strpos($text, $word) !== false) {
                            $back = ((int) $sentAt->dayOfWeek - $weekday + 7) % 7;
                            $date = $sentAt->startOfDay()->subDays($back);
                            break;
                        }
                    }
                }
            }
        }
        if ($date === null) {
            return null;
        }
        if (preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)\s*(pm|p\.m\.|مساء|مساءً|م(?!\p{L})|am|a\.m\.|صباحا|صباحاً|ص(?!\p{L}))?/u', $text, $time)
            && (int) $time[1] < 24 && (int) $time[2] < 60) {
            $hour = (int) $time[1];
            $suffix = $time[3] ?? '';
            // "9:30 PM" / «9:30 مساءً» is 21:30; reading it as 09:30 would put a wrong time in the record.
            if ($hour < 12 && in_array($suffix, ['pm', 'p.m.', 'مساء', 'مساءً', 'م'], true)) {
                $hour += 12;
            } elseif ($hour === 12 && in_array($suffix, ['am', 'a.m.', 'صباحا', 'صباحاً', 'ص'], true)) {
                $hour = 0;
            }
            $date = $date->setTime($hour, (int) $time[2]);
        }

        return $date->greaterThan($sentAt) ? null : $date;
    }

    private static function calendar(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'Asia/Baghdad') : null;
    }
}
