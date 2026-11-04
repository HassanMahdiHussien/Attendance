<?php

namespace App\Services\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\SpreadsheetReader;
use Illuminate\Http\UploadedFile;

class AnnouncementPeople
{
    public function read(Company $company, UploadedFile $file): array
    {
        $sheet = app(SpreadsheetReader::class)->read($file);
        $columns = [];
        foreach ($sheet['rows'] as $row) {
            foreach ($row['cells'] as $index => $cell) {
                if (is_string($cell) || is_int($cell)) {
                    $value = trim((string) $cell);
                    if ($value !== '') {
                        $columns[$index][$value] = true;
                    }
                }
            }
        }
        $matches = [];
        foreach ($columns as $index => $codes) {
            $matches[$index] = Employee::query()->where('company_id', $company->id)
                ->whereIn('employee_code', array_keys($codes))->count();
        }
        if ($matches === []) {
            return ['codes' => [], 'unknown' => []];
        }
        arsort($matches, SORT_NUMERIC);
        $codes = array_keys($columns[array_key_first($matches)]);
        $known = Employee::query()->where('company_id', $company->id)->whereIn('employee_code', $codes)
            ->pluck('employee_code')->all();

        return ['codes' => $codes, 'unknown' => array_values(array_diff($codes, $known))];
    }
}
