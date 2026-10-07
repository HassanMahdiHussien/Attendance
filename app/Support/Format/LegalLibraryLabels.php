<?php

namespace App\Support\Format;

use App\Documents\IraqiDate;
use Carbon\CarbonInterface;

class LegalLibraryLabels
{
    public static function category(string $value): string
    {
        return match ($value) {
            'labor' => 'عمل', 'social_security' => 'ضمان اجتماعي',
            'regulation' => 'نظام داخلي', 'other' => 'أخرى', default => 'تصنيف غير معروف',
        };
    }

    public static function jurisdiction(string $value): string
    {
        return match ($value) {
            'federal_iraq' => 'العراق الاتحادي', 'kurdistan_region' => 'إقليم كوردستان',
            default => 'نطاق غير معروف',
        };
    }

    public static function unitStatus(string $value): string
    {
        return match ($value) {
            'unverified' => 'غير موثقة', 'mismatch' => 'بحاجة لمراجعة',
            'verified_auto' => 'موثقة تلقائياً', 'verified_human' => 'موثقة بمراجعة',
            'verified_text' => 'من نص المصدر', 'excluded' => 'مستبعدة', default => 'حالة غير معروفة',
        };
    }

    public static function runState(string $value): string
    {
        return match ($value) {
            'queued' => 'بالانتظار', 'reading' => 'قيد القراءة',
            'ai_pending' => 'بانتظار قراءة الذكاء الاصطناعي', 'splitting' => 'قيد التقسيم',
            'completed' => 'مكتملة', 'failed' => 'فشلت', 'cancelled' => 'ملغاة',
            default => 'حالة غير معروفة',
        };
    }

    public static function pageStatus(string $value): string
    {
        return match ($value) {
            'pending' => 'بالانتظار', 'done' => 'تمت', 'failed' => 'فشلت',
            'unavailable' => 'غير متاحة', default => 'حالة غير معروفة',
        };
    }

    public static function textMethod(string $value): string
    {
        return match ($value) {
            'text' => 'طبقة النص', 'ocr' => 'OCR', default => 'طريقة غير معروفة',
        };
    }

    public static function mismatchReason(string $value): string
    {
        return match ($value) {
            'text_differs' => 'اختلاف في النص', 'structure_differs' => 'اختلاف في البنود',
            'one_reading_only' => 'ظهرت في قراءة واحدة فقط',
            'duplicate_heading' => 'عنوان مكرر — قد يكون نصاً معدلاً',
            'page_failed' => 'تعذرت قراءة صفحة', 'illegible' => 'نص غير مقروء',
            default => 'اختلاف القراءتين',
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
        foreach (['pages' => 'صفحات', 'units' => 'وحدات', 'verified_auto' => 'موثقة تلقائياً',
            'verified_human' => 'موثقة بمراجعة', 'verified_text' => 'من نص المصدر',
            'mismatch' => 'بحاجة لمراجعة', 'unverified' => 'غير موثقة', 'excluded' => 'مستبعدة'] as $key => $label) {
            if (array_key_exists($key, $counts)) {
                $parts[] = ArabicDigits::convert((int) $counts[$key]).' '.$label;
            }
        }

        return $parts ? implode(' — ', $parts) : '—';
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? ArabicDigits::convert(IraqiDate::format($date, 'ar').'، '.$date->format('H:i')) : '—';
    }
}
