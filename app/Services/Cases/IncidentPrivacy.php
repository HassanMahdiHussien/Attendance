<?php

namespace App\Services\Cases;

use App\Models\DisciplinaryCase;
use Illuminate\Support\Facades\DB;

class IncidentPrivacy
{
    public function containsOtherMemberName(DisciplinaryCase $case, string $text): bool
    {
        if ($case->incident_id === null || $text === '') {
            return false;
        }

        $others = DB::table('cases')->join('employees', function ($join): void {
            $join->on('employees.id', '=', 'cases.employee_id')
                ->on('employees.company_id', '=', 'cases.company_id');
        })->where('cases.company_id', $case->company_id)->where('cases.incident_id', $case->incident_id)
            ->where('cases.id', '!=', $case->id)->get(['employees.name', 'employees.name_en']);
        foreach ($others as $other) {
            foreach ([$other->name, $other->name_en] as $name) {
                if (filled($name) && mb_stripos($text, trim($name)) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
