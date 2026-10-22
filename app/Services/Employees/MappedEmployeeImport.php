<?php

namespace App\Services\Employees;

use App\Enums\EmployeeExitStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MappedEmployeeImport
{
    public function __construct(
        private SpreadsheetReader $reader,
        private ColumnMapping $columns,
        private EmployeeHistory $history,
        private AuditLogger $audit,
        private ExitReview $exitReview,
    ) {}

    public function preview(Company $company, UploadedFile $file, string $mode, string $effectiveDate, array $mapping): ImportPreview
    {
        Gate::authorize('importEmployees', $company);
        if (! in_array($mode, ['full', 'partial'], true)) {
            throw ValidationException::withMessages(['mode' => __('messages.employee_import.mode_invalid')]);
        }
        $date = $this->date($effectiveDate);
        if ($date === null) {
            throw ValidationException::withMessages(['effectiveDate' => __('messages.employee_import.effective_date_invalid')]);
        }
        $sheet = $this->reader->read($file);
        $mapping = $this->columns->validate($mapping, $sheet['headers'], $mode);
        $prior = EmployeeImportBatch::query()->where('company_id', $company->id)->where('file_sha256', $sheet['hash'])->first();
        $employees = Employee::query()->where('company_id', $company->id)->get()->keyBy('employee_code');
        $branches = Branch::query()->where('company_id', $company->id)->get()->groupBy(fn (Branch $branch): string => mb_strtolower(trim($branch->name)));
        $rows = [];
        $seen = [];
        foreach ($sheet['rows'] as $raw) {
            $input = [];
            $errors = [];
            $warnings = [];
            foreach ($mapping as $index => $field) {
                try {
                    $input[$field] = $this->reader->value($raw['cells'][$index] ?? null, $field);
                } catch (ValidationException) {
                    $errors[] = __('messages.employee_import.ambiguous_id');
                    $input[$field] = '';
                }
            }
            $code = $input['employee_code'] ?? '';
            $employee = $employees->get($code);
            if (! $raw['columns_valid']) {
                $errors[] = __('messages.employee_import.wrong_column_count');
            }
            if ($code === '') {
                $errors[] = __('messages.employee_import.field_required', ['field' => __('messages.employee_import.fields.employee_code')]);
            } elseif (mb_strlen($code) > 255) {
                $errors[] = __('messages.employee_import.field_too_long', ['field' => 'employee_code']);
            } elseif (isset($seen[$code])) {
                $errors[] = __('messages.employee_import.duplicate_code');
                $rows[$seen[$code]]['errors'][] = __('messages.employee_import.duplicate_code');
                $rows[$seen[$code]]['status'] = 'error';
            } else {
                $seen[$code] = count($rows);
            }
            if (($input['name'] ?? '') === '' && ($input['name_en'] ?? '') !== '') {
                $input['name'] = $input['name_en'];
            }
            if (($mode === 'full' || $employee === null) && ($input['name'] ?? '') === '') {
                $errors[] = __('messages.employee_import.field_required', ['field' => __('messages.employee_import.fields.name')]);
            }
            $values = [];
            foreach ($input as $field => $value) {
                if ($value === '' || in_array($field, ['employee_code', 'branch', 'manager_code'], true)) {
                    continue;
                }
                if (mb_strlen($value) > 255) {
                    $errors[] = __('messages.employee_import.field_too_long', ['field' => $field]);

                    continue;
                }
                if ($field === 'hire_date') {
                    $value = $this->date($value);
                    if ($value === null || $value > today()->toDateString()) {
                        $errors[] = __('messages.employee_import.hire_date_invalid');

                        continue;
                    }
                }
                if ($field === 'gender') {
                    $value = collect(config('employee_import.gender_values'))
                        ->filter(fn (array $aliases): bool => in_array(mb_strtolower($value), array_map('mb_strtolower', $aliases), true))
                        ->keys()->first();
                    if ($value === null) {
                        $errors[] = __('messages.employee_import.gender_invalid');

                        continue;
                    }
                }
                if ($field === 'email' || $field === 'fallback_notification_email') {
                    if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = __('messages.employee_import.email_invalid');

                        continue;
                    }
                }
                if ($field === 'work_pattern' && ! in_array(mb_strtolower($value), ['office', 'shift', 'security'], true)) {
                    $warnings[] = __('messages.employee_import.pattern_unknown');

                    continue;
                }
                if ($field === 'work_pattern') {
                    $value = mb_strtolower($value);
                }
                if (in_array($field, ['schedule_start', 'schedule_end'], true)) {
                    $time = strlen($value) === 5 ? $value.':00' : $value;
                    if (! preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
                        $errors[] = __('messages.employee_import.time_invalid');

                        continue;
                    }
                    [$hour, $minute, $second] = array_map('intval', explode(':', $time));
                    if ($hour > 23 || $minute > 59 || $second > 59) {
                        $errors[] = __('messages.employee_import.time_invalid');

                        continue;
                    }
                    $value = $time;
                }
                if ($field === 'rest_days') {
                    $days = array_map('intval', preg_split('/[,; ]+/', $value));
                    if (array_filter($days, fn (int $day): bool => $day < 1 || $day > 7)) {
                        $warnings[] = __('messages.employee_import.rest_days_unknown');

                        continue;
                    }
                    $value = array_values(array_unique($days));
                    sort($value);
                }
                $values[$field] = $value;
            }
            if (($input['position'] ?? '') === '') {
                $warnings[] = __('messages.employee_import.position_missing');
            }
            if (($input['branch'] ?? '') !== '') {
                $matches = $branches->get(mb_strtolower(trim($input['branch'])), collect());
                if ($matches->count() === 1) {
                    $values['branch_id'] = $matches->first()->id;
                } else {
                    $errors[] = $matches->isEmpty()
                        ? __('messages.employee_import.branch_unknown_value', ['branch' => $input['branch']])
                        : __('messages.employee_import.branch_ambiguous');
                }
            } elseif ($mode === 'full' || $employee === null) {
                $errors[] = __('messages.employee_import.field_required', ['field' => __('messages.employee_import.fields.branch')]);
            }
            if ($employee === null && ! isset($values['hire_date'])) {
                $values['hire_date'] = $date;
            }
            $before = $employee ? $this->effectiveValues($employee, $date) : [];
            $diff = [];
            foreach ($values as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $diff[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
                }
            }
            if ($mode === 'full' && $employee?->exit_status === EmployeeExitStatus::PendingReview) {
                $diff['exit_status'] = ['before' => 'pending_review', 'after' => 'none'];
            }
            $rows[] = [
                'number' => $raw['number'], 'employee_id' => $employee?->id,
                'code' => $code, 'name' => $input['name'] ?? $employee?->name,
                'branch' => $input['branch'] ?? '', 'values' => $values,
                'manager_code' => $input['manager_code'] ?? '',
                'errors' => $errors, 'warnings' => $warnings, 'diff' => $diff,
                'status' => $errors ? 'error' : ($employee === null ? 'new' : ($diff ? 'update' : 'unchanged')),
            ];
        }
        $validCodes = array_fill_keys(array_column(array_filter($rows, fn (array $row): bool => $row['status'] !== 'error'), 'code'), true);
        foreach ($rows as &$row) {
            $managerCode = $row['manager_code'];
            if ($row['status'] === 'error') {
                continue;
            }
            if ($managerCode === '') {
                if (($row['values']['manager_name'] ?? '') !== '') {
                    $row['warnings'][] = __('messages.employee_import.manager_code_missing');
                }

                continue;
            }
            $manager = $employees->get($managerCode);
            if ($managerCode === $row['code'] || ($manager === null && ! isset($validCodes[$managerCode]))) {
                $row['warnings'][] = __('messages.employee_import.manager_unmatched', ['code' => $managerCode]);

                continue;
            }
            $row['resolved_manager_code'] = $managerCode;
            $beforeId = $row['employee_id'] ? $this->effectiveValues($employees->get($row['code']), $date)['manager_employee_id'] : null;
            $afterId = $manager?->id;
            if ($afterId === null || $beforeId !== $afterId) {
                $row['diff']['manager_employee_id'] = [
                    'before' => $beforeId === null ? null : ($employees->firstWhere('id', $beforeId)?->employee_code ?? $beforeId),
                    'after' => $managerCode,
                ];
                if ($row['status'] === 'unchanged') {
                    $row['status'] = 'update';
                }
            }
        }
        unset($row);

        $missing = $mode === 'full' ? $employees->filter(fn (Employee $employee): bool => $employee->is_active && ! isset($seen[$employee->employee_code]))
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'code' => $employee->employee_code, 'name' => $employee->name])->values()->all() : [];
        $priorInfo = $prior ? ['date' => $prior->effective_date->toDateString(), 'mode' => $prior->mode, 'id' => $prior->id] : null;
        $preview = new ImportPreview($rows, [], $sheet['hash'], $missing, $priorInfo);
        if ($prior === null) {
            $preview->token = $this->token($company, $mode, $date, $mapping, $preview);
        }

        return $preview;
    }

    public function commit(Company $company, UploadedFile $file, User $actor, string $mode, string $effectiveDate, array $mapping, string $token): array
    {
        Gate::authorize('importEmployees', $company);
        abort_unless((int) auth()->id() === (int) $actor->id, 403);
        $storedPath = null;
        try {
            return DB::transaction(function () use ($company, $file, $actor, $mode, $effectiveDate, $mapping, $token, &$storedPath): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $preview = $this->preview($company, $file, $mode, $effectiveDate, $mapping);
                if ($preview->alreadyImported !== null) {
                    return ['committed' => false, 'preview' => $preview, 'alreadyImported' => true];
                }
                if ($preview->token === null || ! hash_equals($preview->token, $token) || $preview->errors() > 0) {
                    return ['committed' => false, 'preview' => $preview];
                }
                $sheet = $this->reader->read($file);
                $mapping = $this->columns->validate($mapping, $sheet['headers'], $mode);
                $storedPath = Storage::disk('local')->putFileAs('employee-imports/'.$company->id, $file, bin2hex(random_bytes(16)).'.'.$file->getClientOriginalExtension());
                if ($storedPath === false) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.storage_failed')]);
                }
                $counts = ['new' => $preview->added(), 'changed' => $preview->changed(), 'unchanged' => $preview->unchanged(), 'missing' => count($preview->missing)];
                $batch = EmployeeImportBatch::create([
                    'company_id' => $company->id, 'uploaded_by' => $actor->id, 'mode' => $mode,
                    'effective_date' => $effectiveDate, 'file_path' => $storedPath,
                    'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'file_sha256' => $preview->hash,
                    'mapping' => $mapping, 'counts' => $counts,
                    'summary' => ['codes' => array_column($preview->rows, 'code'),
                        'changed_fields' => array_values(array_unique(Arr::flatten(array_map(fn (array $row): array => array_keys($row['diff']), $preview->rows)))),
                        'missing_codes' => array_column($preview->missing, 'code')],
                ]);
                $byCode = Employee::query()->where('company_id', $company->id)->get()->keyBy('employee_code');
                foreach ($preview->rows as $row) {
                    if ($row['status'] !== 'new') {
                        continue;
                    }
                    $values = Arr::except($row['values'], ['manager_employee_id']);
                    $identity = Arr::only($values, ['name', 'name_en', 'branch_id', 'hire_date', 'gender'])
                        + ['employee_code' => $row['code'], 'company_id' => $company->id];
                    $identity['gender'] ??= 'unspecified';
                    $employee = $this->history->create($identity, $values, $effectiveDate, 'import', $actor, batchId: $batch->id);
                    $byCode->put($row['code'], $employee);
                }
                foreach ($preview->rows as $row) {
                    $employee = $byCode->get($row['code']);
                    $values = $row['status'] === 'new' ? [] : $row['values'];
                    if (isset($row['resolved_manager_code'])) {
                        $values['manager_employee_id'] = $byCode->get($row['resolved_manager_code'])?->id;
                    }
                    if ($values !== []) {
                        $this->history->record($employee, $values, $effectiveDate, 'import', $actor, batchId: $batch->id);
                    }
                    if ($mode === 'full' && $employee->exit_status === EmployeeExitStatus::PendingReview) {
                        $this->exitReview->reappear($employee, $batch, $actor);
                    }
                }
                foreach ($preview->missing as $missing) {
                    $this->exitReview->start($byCode->get($missing['code']), $actor, $batch);
                }
                $this->columns->save($company, $actor, $sheet['headers'], $mapping);
                $this->audit->log('employees.imported', $company, ['batch_id' => $batch->id, 'sha256' => $preview->hash, 'counts' => $counts], $actor);

                return ['committed' => true, 'preview' => $preview, 'batch' => $batch, 'counts' => $counts];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function token(Company $company, string $mode, string $date, array $mapping, ImportPreview $preview): string
    {
        return hash_hmac('sha256', json_encode([$company->id, auth()->id(), $preview->hash, $mode, $date, $mapping,
            $preview->rows, $preview->missing], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function effectiveValues(Employee $employee, string $date): array
    {
        if ($employee->versions()->exists()) {
            return $this->history->asOf($employee, $date);
        }

        $initialDate = $employee->hire_date?->toDateString() ?? $employee->created_at?->toDateString() ?? today()->toDateString();
        $initialDate = min($initialDate, today()->toDateString());

        return $date >= $initialDate ? $this->history->valuesFrom($employee) : array_fill_keys(EmployeeHistory::FIELDS, null);
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // Another supported date format may still be valid.
            }
        }

        return null;
    }
}
