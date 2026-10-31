<?php

namespace App\Services\Employees;

use App\Enums\SequenceKey;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branches\BranchMatcher;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeImport
{
    public function __construct(private CsvReader $reader, private SequenceService $sequences, private AuditLogger $audit, private EmployeeHistory $history, private PatternRule $patternRule) {}

    public function inspect(Company $company, UploadedFile $file): array
    {
        Gate::authorize('importEmployees', $company);

        return app(SpreadsheetReader::class)->read($file);
    }

    public function previewMapped(Company $company, UploadedFile $file, string $mode, string $effectiveDate, array $mapping): ImportPreview
    {
        Gate::authorize('importEmployees', $company);

        return app(MappedEmployeeImport::class)->preview($company, $file, $mode, $effectiveDate, $mapping);
    }

    public function commitMapped(Company $company, UploadedFile $file, User $actor, string $mode, string $effectiveDate, array $mapping, string $token): array
    {
        Gate::authorize('importEmployees', $company);
        abort_unless((int) auth()->id() === (int) $actor->id, 403);

        return app(MappedEmployeeImport::class)->commit($company, $file, $actor, $mode, $effectiveDate, $mapping, $token);
    }

    public function preview(Company $company, UploadedFile $file, bool $lock = false): ImportPreview
    {
        Gate::authorize('importEmployees', $company);
        $csv = $this->reader->read($file);
        $branches = app(BranchMatcher::class)->forCompany($company, withoutScopes: true);
        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy(fn (Employee $employee): string => $this->normalise($employee->employee_code));
        $rows = [];
        $codes = [];

        foreach ($csv['rows'] as $raw) {
            $input = $raw['values'];
            $code = trim($input['employee_code'] ?? '');
            $key = $this->normalise($code);
            $existing = $code !== '' ? $employees->get($key) : null;
            $errors = [];
            $warnings = [];
            if (! $raw['columns_valid']) {
                $errors[] = __('messages.employee_import.wrong_column_count');
            }
            foreach (['name', 'hire_date', 'branch'] as $field) {
                if (trim($input[$field] ?? '') === '') {
                    $errors[] = __('messages.employee_import.field_required', ['field' => __('messages.employee_import.fields.'.$field)]);
                }
            }
            foreach (['name', 'employee_code', 'department', 'position', 'email', 'legal_entity'] as $field) {
                if (mb_strlen($input[$field] ?? '') > 255) {
                    $errors[] = __('messages.employee_import.field_too_long', ['field' => $field]);
                }
            }
            $branchMatches = $branches->matches($input['branch'] ?? '');
            if (($input['branch'] ?? '') !== '') {
                if ($branchMatches->count() === 0) {
                    $warnings[] = __('messages.employee_import.branch_held');
                } elseif ($branchMatches->count() > 1) {
                    $errors[] = __('messages.employee_import.branch_ambiguous');
                }
            }
            $date = $this->date($input['hire_date'] ?? '');
            if (($input['hire_date'] ?? '') !== '' && ($date === null || $date > today()->toDateString())) {
                $errors[] = __('messages.employee_import.hire_date_invalid');
            }
            if (($input['email'] ?? '') !== '' && ! filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('messages.employee_import.email_invalid');
            }
            $gender = match ($this->normalise($input['gender'] ?? '')) {
                'male', 'ذكر' => 'male',
                'female', 'أنثى' => 'female',
                '' => null,
                default => false,
            };
            if ($gender === false) {
                $errors[] = __('messages.employee_import.gender_invalid');
            }
            $category = match ($this->normalise($input['staff_category'] ?? '')) {
                'b', 'blue' => 'B',
                'w', 'white' => 'W',
                '' => null,
                default => false,
            };
            if ($category === false) {
                $errors[] = __('messages.employee.staff_category_invalid');
            }
            $values = [
                'name' => $input['name'] ?? '',
                'employee_code' => $existing?->employee_code ?? $code,
                'department' => ($input['department'] ?? '') !== '' ? $input['department'] : $existing?->department,
                'position' => ($input['position'] ?? '') !== '' ? $input['position'] : $existing?->position,
                'hire_date' => $date,
                'branch_id' => $branchMatches->count() === 1 ? $branchMatches->first()->id : null,
                'email' => ($input['email'] ?? '') !== '' ? $input['email'] : ($existing?->email),
                'gender' => $gender ?: ($existing?->gender?->value ?? 'unspecified'),
                'staff_category' => $category ?: $existing?->staff_category,
                'legal_entity' => ($input['legal_entity'] ?? '') !== '' ? $input['legal_entity'] : $existing?->legal_entity,
            ];
            $diff = [];
            if ($existing) {
                foreach (['name', 'department', 'position', 'hire_date', 'branch_id', 'email', 'gender', 'staff_category', 'legal_entity'] as $field) {
                    $before = $field === 'hire_date' ? $existing->hire_date?->toDateString()
                        : ($field === 'gender' ? $existing->gender?->value : $existing->{$field});
                    if ((string) $before !== (string) $values[$field]) {
                        $diff[] = $field;
                    }
                }
                if (in_array('branch_id', $diff, true)) {
                    if (User::query()->where('employee_id', $existing->id)->exists()) {
                        $warnings[] = __('messages.employee_import.linked_user_permissions_unchanged');
                    }
                }
            }
            if ($existing?->work_pattern === null && ($rule = $this->patternRule->suggest($values)) !== null) {
                $diff[] = 'work_pattern';
            }
            $rowIndex = count($rows);
            if ($key !== '') {
                if (isset($codes[$key])) {
                    $errors[] = __('messages.employee_import.duplicate_code');
                    $rows[$codes[$key]]['errors'][] = __('messages.employee_import.duplicate_code');
                    $rows[$codes[$key]]['status'] = 'error';
                } else {
                    $codes[$key] = $rowIndex;
                }
            }
            $rows[] = [
                'number' => $raw['number'], 'status' => $errors ? 'error' : ($branchMatches->isEmpty() ? 'held' : ($existing ? ($existing->is_active ? 'update' : 'update-inactive') : 'new')),
                'errors' => $errors, 'warnings' => $warnings, 'values' => $values, 'diff' => $diff,
                'employee_id' => $existing?->id, 'branch' => $input['branch'] ?? '',
            ];
        }

        return new ImportPreview($rows, $csv['notices'], $csv['hash']);
    }

    public function commit(Company $company, UploadedFile $file, User $actor, string $expectedHash): array
    {
        Gate::authorize('importEmployees', $company);
        abort_unless((int) auth()->id() === (int) $actor->id, 403);
        if (! hash_equals($expectedHash, hash_file('sha256', $file->getRealPath()))) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.file_changed')]);
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($company, $file, $actor, $expectedHash, &$storedPath): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('importEmployees', $company);
                if (! hash_equals($expectedHash, hash_file('sha256', $file->getRealPath()))) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.file_changed')]);
                }
                $prior = EmployeeImportBatch::completeImportOf($company->id, $expectedHash);
                if ($prior !== null) {
                    return ['committed' => false, 'preview' => $this->preview($company, $file, true), 'alreadyImported' => true];
                }
                $preview = $this->preview($company, $file, true);
                if (! hash_equals($expectedHash, $preview->hash)) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.file_changed')]);
                }
                if ($preview->errors() > 0) {
                    return ['committed' => false, 'preview' => $preview];
                }
                $storedPath = Storage::disk('local')->putFileAs('employee-imports/'.$company->id, $file, bin2hex(random_bytes(16)).'.csv');
                if ($storedPath === false) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.employee_import.storage_failed')]);
                }
                $sheet = app(SpreadsheetReader::class)->read($file);
                $importedRows = array_values(array_filter($preview->rows, fn (array $row): bool => $row['status'] !== 'held'));
                $batch = EmployeeImportBatch::create([
                    'company_id' => $company->id, 'uploaded_by' => $actor->id, 'mode' => 'partial',
                    'effective_date' => today()->toDateString(), 'file_path' => $storedPath,
                    'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'file_sha256' => $preview->hash,
                    'mapping' => app(ColumnMapping::class)->suggest($sheet['headers']),
                    'counts' => ['new' => $preview->added(), 'changed' => count(array_filter($importedRows, fn (array $row): bool => $row['diff'] !== [])),
                        'unchanged' => count(array_filter($importedRows, fn (array $row): bool => $row['diff'] === [] && $row['employee_id'] !== null)), 'missing' => 0,
                        'skipped' => $preview->held()],
                    'summary' => ['codes' => array_values(array_filter(array_column(array_column($importedRows, 'values'), 'employee_code'))),
                        'changed_fields' => array_values(array_unique(Arr::flatten(array_column($importedRows, 'diff'))))],
                ]);
                $used = Employee::withoutGlobalScopes()->where('company_id', $company->id)->pluck('employee_code')
                    ->mapWithKeys(fn (string $code): array => [$this->normalise($code) => true])->all();
                foreach ($preview->rows as $row) {
                    if ($row['status'] === 'new' && $row['values']['employee_code'] !== '') {
                        $used[$this->normalise($row['values']['employee_code'])] = true;
                    }
                }
                $changed = [];
                $touchedEmployeeIds = [];
                foreach ($preview->rows as $row) {
                    if ($row['status'] === 'held') {
                        continue;
                    }
                    if ($row['employee_id'] === null) {
                        continue;
                    }
                    $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($row['employee_id']);
                    $touchedEmployeeIds[] = $employee->id;
                    $this->history->record($employee, Arr::except($row['values'], ['employee_code']), today(), 'import', $actor, batchId: $batch->id);
                    if ($row['diff'] !== []) {
                        $changed[] = ['id' => $employee->id, 'fields' => $row['diff']];
                    }
                }
                foreach ($preview->rows as $row) {
                    if ($row['status'] === 'held') {
                        continue;
                    }
                    if ($row['employee_id'] !== null) {
                        continue;
                    }
                    $values = $row['values'];
                    if ($values['employee_code'] === '') {
                        do {
                            $number = $this->sequences->next((int) $company->id, SequenceKey::EmployeeCode);
                            $values['employee_code'] = 'EMP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
                        } while (isset($used[$this->normalise($values['employee_code'])]));
                    }
                    $used[$this->normalise($values['employee_code'])] = true;
                    $employee = $this->history->create(
                        Arr::only($values, ['name', 'employee_code', 'branch_id', 'hire_date', 'gender']) + ['company_id' => $company->id],
                        Arr::except($values, ['employee_code']),
                        // First imported values count from hiring (see MappedEmployeeImport::firstImportDate).
                        is_string($values['hire_date'] ?? null) && $values['hire_date'] !== ''
                            && $values['hire_date'] < today()->toDateString() ? $values['hire_date'] : today(),
                        'import',
                        $actor,
                        batchId: $batch->id,
                    );
                    $touchedEmployeeIds[] = $employee->id;
                }
                foreach ($touchedEmployeeIds as $employeeId) {
                    $this->patternRule->apply(Employee::query()->findOrFail($employeeId), today()->toDateString(), $batch->id);
                }
                $this->audit->log('employees.imported', $company, [
                    'sha256' => $preview->hash, 'added' => $preview->added(), 'updated' => $preview->updated(),
                    'changed' => $changed,
                ], $actor);

                return ['committed' => true, 'preview' => $preview, 'added' => $preview->added(), 'updated' => $preview->updated(), 'batch' => $batch];
            });
        } catch (QueryException $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $preview = $this->preview($company, $file);
            if ($preview->errors() === 0) {
                $preview->rows[0]['status'] = 'error';
                $preview->rows[0]['errors'][] = __('messages.employee_import.data_changed');
            }

            return ['committed' => false, 'preview' => $preview];
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
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
                // The second supported format may still be valid.
            }
        }

        return null;
    }
}
