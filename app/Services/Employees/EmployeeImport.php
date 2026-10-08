<?php

namespace App\Services\Employees;

use App\Enums\CaseStatus;
use App\Enums\SequenceKey;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeeImport
{
    public function __construct(private CsvReader $reader, private SequenceService $sequences, private AuditLogger $audit) {}

    public function preview(Company $company, UploadedFile $file, bool $lock = false): ImportPreview
    {
        Gate::authorize('importEmployees', $company);
        $csv = $this->reader->read($file);
        $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->get()->groupBy(fn (Branch $branch): string => $this->normalise($branch->name));
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
                $errors[] = 'عدد الأعمدة غير صحيح';
            }
            foreach (['name' => 'الاسم', 'department' => 'القسم', 'position' => 'المنصب', 'hire_date' => 'تاريخ التعيين', 'branch' => 'الفرع'] as $field => $label) {
                if (trim($input[$field] ?? '') === '') {
                    $errors[] = "$label مطلوب";
                }
            }
            foreach (['name', 'employee_code', 'department', 'position', 'email'] as $field) {
                if (mb_strlen($input[$field] ?? '') > 255) {
                    $errors[] = "$field يتجاوز 255 حرفاً";
                }
            }
            $branchMatches = $branches->get($this->normalise($input['branch'] ?? ''), collect());
            if (($input['branch'] ?? '') !== '') {
                if ($branchMatches->count() === 0) {
                    $errors[] = 'الفرع غير معروف';
                } elseif ($branchMatches->count() > 1) {
                    $errors[] = 'اسم الفرع غير مميز';
                }
            }
            $date = $this->date($input['hire_date'] ?? '');
            if (($input['hire_date'] ?? '') !== '' && ($date === null || $date > today()->toDateString())) {
                $errors[] = 'تاريخ التعيين غير صالح';
            }
            if (($input['email'] ?? '') !== '' && ! filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'البريد الإلكتروني غير صالح';
            }
            $gender = match ($this->normalise($input['gender'] ?? '')) {
                'male', 'ذكر' => 'male',
                'female', 'أنثى' => 'female',
                '' => null,
                default => false,
            };
            if ($gender === false) {
                $errors[] = 'الجنس غير صالح';
            }
            $values = [
                'name' => $input['name'] ?? '',
                'employee_code' => $existing?->employee_code ?? $code,
                'department' => $input['department'] ?? '',
                'position' => $input['position'] ?? '',
                'hire_date' => $date,
                'branch_id' => $branchMatches->count() === 1 ? $branchMatches->first()->id : null,
                'email' => ($input['email'] ?? '') !== '' ? $input['email'] : ($existing?->email),
                'gender' => $gender ?: ($existing?->gender?->value ?? 'unspecified'),
            ];
            $diff = [];
            if ($existing) {
                foreach (['name', 'department', 'position', 'hire_date', 'branch_id', 'email', 'gender'] as $field) {
                    $before = $field === 'hire_date' ? $existing->hire_date?->toDateString()
                        : ($field === 'gender' ? $existing->gender?->value : $existing->{$field});
                    if ((string) $before !== (string) $values[$field]) {
                        $diff[] = $field;
                    }
                }
                if (in_array('branch_id', $diff, true)) {
                    if (DisciplinaryCase::withoutGlobalScopes()->where('company_id', $company->id)
                        ->where('employee_id', $existing->id)->whereNotIn('status', [
                            CaseStatus::Draft->value, CaseStatus::ArchivedFinal->value, CaseStatus::AppealResolved->value,
                        ])->exists()) {
                        $warnings[] = 'الحالة المفتوحة تبقى في فرعها';
                    }
                    if (User::query()->where('employee_id', $existing->id)->exists()) {
                        $warnings[] = 'صلاحيات المستخدم المرتبط لا تتغير';
                    }
                }
            }
            $rowIndex = count($rows);
            if ($key !== '') {
                if (isset($codes[$key])) {
                    $errors[] = 'رقم مكرر في الملف';
                    $rows[$codes[$key]]['errors'][] = 'رقم مكرر في الملف';
                    $rows[$codes[$key]]['status'] = 'error';
                } else {
                    $codes[$key] = $rowIndex;
                }
            }
            $rows[] = [
                'number' => $raw['number'], 'status' => $errors ? 'error' : ($existing ? ($existing->is_active ? 'update' : 'update-inactive') : 'new'),
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
            throw ValidationException::withMessages(['sourceFile' => 'أعد المعاينة: الملف تغيّر.']);
        }

        try {
            return DB::transaction(function () use ($company, $file, $actor, $expectedHash): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('importEmployees', $company);
                if (! hash_equals($expectedHash, hash_file('sha256', $file->getRealPath()))) {
                    throw ValidationException::withMessages(['sourceFile' => 'أعد المعاينة: الملف تغيّر.']);
                }
                $preview = $this->preview($company, $file, true);
                if (! hash_equals($expectedHash, $preview->hash)) {
                    throw ValidationException::withMessages(['sourceFile' => 'أعد المعاينة: الملف تغيّر.']);
                }
                if ($preview->errors() > 0) {
                    return ['committed' => false, 'preview' => $preview];
                }
                $used = Employee::withoutGlobalScopes()->where('company_id', $company->id)->pluck('employee_code')
                    ->mapWithKeys(fn (string $code): array => [$this->normalise($code) => true])->all();
                foreach ($preview->rows as $row) {
                    if ($row['status'] === 'new' && $row['values']['employee_code'] !== '') {
                        $used[$this->normalise($row['values']['employee_code'])] = true;
                    }
                }
                $changed = [];
                foreach ($preview->rows as $row) {
                    if ($row['employee_id'] === null) {
                        continue;
                    }
                    $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($row['employee_id']);
                    $employee->fill($row['values']);
                    $employee->save();
                    if ($row['diff'] !== []) {
                        $changed[] = ['id' => $employee->id, 'fields' => $row['diff']];
                    }
                }
                foreach ($preview->rows as $row) {
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
                    Employee::create($values + ['company_id' => $company->id]);
                }
                $this->audit->log('employees.imported', $company, [
                    'sha256' => $preview->hash, 'added' => $preview->added(), 'updated' => $preview->updated(),
                    'changed' => $changed,
                ], $actor);

                return ['committed' => true, 'preview' => $preview, 'added' => $preview->added(), 'updated' => $preview->updated()];
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $preview = $this->preview($company, $file);
            if ($preview->errors() === 0) {
                $preview->rows[0]['status'] = 'error';
                $preview->rows[0]['errors'][] = 'تغيّرت البيانات أثناء الاستيراد؛ أعد المعاينة';
            }

            return ['committed' => false, 'preview' => $preview];
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
