<?php

namespace App\Services\Attendance;

use App\Models\Attendance\ImportMapping as AttendanceMapping;
use App\Models\Company;
use App\Models\EmployeeImportMapping;
use App\Models\Leave\BalanceMapping;
use App\Models\Leave\ImportMapping as LeaveMapping;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeImport;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadDetector
{
    public function detect(Company $company, UploadedFile $file, array $allowed = ['attendance', 'leave', 'leave_balance', 'employees']): array
    {
        $name = $file->getClientOriginalName();
        $matches = [];
        $damagedHeader = false;
        foreach ($allowed as $kind) {
            try {
                $matches[$kind] = match ($kind) {
                    'attendance' => app(TransactionFile::class)->inspect($file),
                    'leave' => app(LeaveFile::class)->inspect($file),
                    'leave_balance' => app(LeaveBalanceFile::class)->inspect($file),
                    'employees' => app(EmployeeImport::class)->inspect($company, $file),
                };
            } catch (ValidationException $exception) {
                // Each inspector owns its header rules; a rejected layout may belong to another report.
                $damagedHeader = $damagedHeader || in_array(__('messages.import.damaged_arabic_header'), $exception->errors()['sourceFile'] ?? [], true);
            }
        }
        if (isset($matches['employees']) && ! preg_match('/\bHC\b|employee/i', $name)
            && ! in_array('employee_code', app(ColumnMapping::class)->suggest($matches['employees']['headers']), true)) {
            unset($matches['employees']);
        }
        $preferred = match (true) {
            preg_match('/^Transaction_\d{14}\.(csv|xlsx)$/i', $name) === 1 => 'attendance',
            stripos($name, 'IRAQ All Leave REPORT') !== false => 'leave',
            preg_match('/balance/i', $name) === 1 => 'leave_balance',
            preg_match('/\bHC\b|employee/i', $name) === 1 => 'employees',
            default => null,
        };
        if ($matches === []) {
            return ['kind' => 'unknown', 'mapping' => null, 'settings' => [], 'preview' => null, 'headers' => [],
                'message' => $damagedHeader ? __('messages.import.damaged_arabic_header') : null];
        }
        $kind = $preferred !== null && isset($matches[$preferred]) ? $preferred : null;
        foreach (['leave_balance', 'leave', 'attendance', 'employees'] as $candidate) {
            if ($kind === null && isset($matches[$candidate])) {
                $kind = $candidate;
            }
        }
        $sheet = $matches[$kind];
        $headers = $sheet['headers'];
        $settings = [];
        $preview = null;
        $saved = false;
        $needsMapping = false;
        $distinctCodes = null;
        if ($kind === 'attendance') {
            $reader = app(TransactionFile::class);
            $signature = hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR));
            $record = AttendanceMapping::query()->where('company_id', $company->id)->where('header_signature', $signature)->first();
            $saved = $record !== null;
            $mapping = $record?->mapping ?? $reader->suggest($headers);
            $needsMapping = ! $saved && ! $this->valid(fn () => $reader->validateMapping($mapping, $headers));
            if (! $needsMapping) {
                $import = app(TransactionImport::class);
                $settings = $this->attendanceSettings($company, $file, $mapping);
                $preview = $import->preview($company, $file, $mapping, $settings);
                if (($preview['counts']['unknown_ids'] ?? 0) > 0) {
                    $codes = [];
                    foreach ($reader->rows($file, $mapping) as $line) {
                        if ($line['errors'] === [] && $line['values']['person_code'] !== '') {
                            $codes[$line['values']['person_code']] = true;
                        }
                    }
                    $distinctCodes = count($codes);
                }
            }
        } elseif ($kind === 'leave') {
            $reader = app(LeaveFile::class);
            $record = LeaveMapping::query()->where('company_id', $company->id)->where('header_signature', $sheet['header_signature'])->first();
            $saved = $record !== null;
            $mapping = $record?->mapping ?? $reader->suggest($headers);
            $settings = ['scope' => 'partial', 'period_from' => null, 'period_to' => null, 'snapshot_at' => $sheet['snapshot_at']];
            $needsMapping = ! $saved && ! $this->valid(fn () => $reader->validateMapping($mapping, $headers));
            if (! $needsMapping) {
                $import = app(LeaveImport::class);
                $settings = $this->leaveSettings($company, $file, $mapping, $settings);
                $preview = $import->preview($company, $file, $mapping, $settings);
            }
        } elseif ($kind === 'leave_balance') {
            $reader = app(LeaveBalanceFile::class);
            $record = BalanceMapping::query()->where('company_id', $company->id)->where('header_signature', $sheet['header_signature'])->first();
            $saved = $record !== null;
            $mapping = $record?->mapping ?? $reader->suggest($headers, $sheet['subheaders']);
            $settings = ['date' => null, 'year' => $sheet['suggested_year']];
            $needsMapping = ! $saved && ! $this->valid(fn () => $reader->validateMapping($mapping, $headers));
        } else {
            $columns = app(ColumnMapping::class);
            $saved = EmployeeImportMapping::query()->where('company_id', $company->id)
                ->where('header_signature', $columns->signature($headers))->exists();
            $mapping = $columns->saved($company, $headers);
            $settings = ['mode' => 'partial', 'date' => today()->toDateString()];
            $needsMapping = ! $saved && ! $this->valid(fn () => $columns->validate($mapping, $headers, 'partial'));
            if (! $needsMapping) {
                $preview = app(EmployeeImport::class)->previewMapped($company, $file, 'partial', $settings['date'], $mapping)->toArray();
            }
        }

        return ['kind' => $kind, 'mapping' => $mapping, 'settings' => $settings, 'preview' => $preview,
            'headers' => $headers, 'subheaders' => $sheet['subheaders'] ?? [], 'needs_mapping' => $needsMapping,
            'distinct_codes' => $distinctCodes];
    }

    private function valid(callable $validate): bool
    {
        try {
            $validate();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    // Coverage HR would otherwise type: the period and branches the file itself shows (spec 513, "pre-filled coverage").
    public function attendanceSettings(Company $company, UploadedFile $file, array $mapping): array
    {
        $settings = app(TransactionImport::class)->preview($company, $file, $mapping, ['branch_ids' => []])['suggested_coverage'];
        foreach (['from', 'to'] as $field) {
            if ($settings[$field] !== null) {
                $settings[$field] = str_replace(' ', 'T', substr($settings[$field], 0, 16));
            }
        }

        return $settings;
    }

    public function leaveSettings(Company $company, UploadedFile $file, array $mapping, array $settings): array
    {
        $suggestion = app(LeaveImport::class)->preview($company, $file, $mapping, $settings);
        $settings['period_from'] = $suggestion['suggested_scope']['period_from'];
        $settings['period_to'] = $suggestion['suggested_scope']['period_to'];

        return $settings;
    }
}
