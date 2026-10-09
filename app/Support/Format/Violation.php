<?php

namespace App\Support\Format;

use App\Models\DisciplinaryCase;
use App\Models\ViolationReport;
use App\Models\ViolationType;
use App\Support\Text\ArabicText;

class Violation
{
    public static function name(DisciplinaryCase|ViolationReport $row, ?string $language = null): string
    {
        if ($row->violation_type_id === null) {
            return ArabicText::westernDigits((string) $row->violation_type);
        }
        $type = $row->relationLoaded('violationType') ? $row->getRelation('violationType')
            : ViolationType::withoutGlobalScopes()->where('company_id', $row->company_id)->find($row->violation_type_id);

        return ArabicText::westernDigits($type?->name($language) ?: (string) $row->violation_type);
    }

    public static function labelForKey(string $key, array $names, ?string $language = null): string
    {
        if (str_starts_with($key, 'txt:')) {
            return ArabicText::westernDigits(substr($key, 4));
        }
        if (! str_starts_with($key, 'vt:')) {
            return '';
        }
        $selected = $names[$language ?? app()->getLocale()] ?? null;

        return ArabicText::westernDigits((string) (filled($selected) ? $selected : ($names['ar'] ?? '')));
    }
}
