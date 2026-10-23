<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\EmployeeImportMapping;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ColumnMapping
{
    public const FIELDS = [
        'employee_code', 'name', 'name_en', 'department', 'position', 'hire_date', 'branch',
        'email', 'gender', 'manager_code', 'manager_name', 'work_pattern', 'rest_days',
        'staff_category', 'legal_entity',
        'schedule_start', 'schedule_end', 'fallback_notification_email',
    ];

    public function signature(array $headers): string
    {
        return hash('sha256', json_encode(array_map(fn (string $header): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $header))), $headers), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function suggest(array $headers): array
    {
        $mapping = [];
        $seenCode = false;
        $hc = count($headers) === 24 && $headers[0] === 'Pers.No.' && $headers[19] === 'Pers.No.';
        if ($hc) {
            $mapping[2] = 'name';
            $mapping[10] = 'staff_category';
            $mapping[16] = 'legal_entity';
        }
        foreach ($headers as $index => $header) {
            foreach (config('employee_import.aliases', []) as $field => $aliases) {
                $normal = fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
                if (! in_array($normal($header), array_map($normal, $aliases), true) || $header === '') {
                    continue;
                }
                if ($field === 'employee_code' && $seenCode && $normal($header) === $normal('Pers.No.')
                    && str_starts_with($normal($headers[$index + 1] ?? ''), 'personnel number of superior')) {
                    $field = 'manager_code';
                }
                if ($field === 'employee_code') {
                    $seenCode = true;
                }
                if (! in_array($field, $mapping, true)) {
                    $mapping[$index] = $field;
                }
                break;
            }
        }
        ksort($mapping);

        return $mapping;
    }

    public function saved(Company $company, array $headers): array
    {
        return EmployeeImportMapping::query()->where('company_id', $company->id)
            ->where('header_signature', $this->signature($headers))->first()?->mapping ?? $this->suggest($headers);
    }

    public function validate(array $mapping, array $headers, string $mode): array
    {
        $clean = [];
        foreach ($mapping as $index => $field) {
            if ($field === null || $field === '') {
                continue;
            }
            if (! ctype_digit((string) $index) || ! isset($headers[(int) $index]) || ! in_array($field, self::FIELDS, true)
                || isset($clean[(int) $index]) || in_array($field, $clean, true)) {
                throw ValidationException::withMessages(['mapping' => __('messages.employee_import.mapping_invalid')]);
            }
            $clean[(int) $index] = $field;
        }
        if (! in_array('employee_code', $clean, true) || ($mode === 'full' && (! in_array('name', $clean, true) || ! in_array('branch', $clean, true)))) {
            throw ValidationException::withMessages(['mapping' => __('messages.employee_import.mapping_required')]);
        }
        ksort($clean);

        return $clean;
    }

    public function save(Company $company, User $actor, array $headers, array $mapping): void
    {
        Gate::authorize('importEmployees', $company);
        EmployeeImportMapping::query()->updateOrCreate(
            ['company_id' => $company->id, 'header_signature' => $this->signature($headers)],
            ['headers' => $headers, 'mapping' => $mapping, 'updated_by' => $actor->id],
        );
    }
}
