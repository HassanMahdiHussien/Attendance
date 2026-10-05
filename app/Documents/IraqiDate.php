<?php

namespace App\Documents;

use Carbon\CarbonInterface;

class IraqiDate
{
    private const AR = [1 => 'كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];

    private const EN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function format(CarbonInterface $date, string $language): string
    {
        $months = $language === 'en' ? self::EN : self::AR;

        return $date->day.' – '.$months[$date->month].' – '.$date->year;
    }
}
