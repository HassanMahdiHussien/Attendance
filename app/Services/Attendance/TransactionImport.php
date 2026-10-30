<?php

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Events\CoverageChanged;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportMapping;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branches\BranchMatcher;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TransactionImport
{
    public function __construct(private TransactionFile $file, private AuditLogger $audit, private PreviewKeys $previewKeys) {}

    public function preview(Company $company, UploadedFile $file, array $mapping, array $coverage): array
    {
        $this->authorize($company);
        $sheet = $this->file->inspect($file);
        $mapping = $this->file->validateMapping($mapping, $sheet['headers']);
        $branches = app(BranchMatcher::class)->forCompany($company);
        $employees = Employee::query()->where('company_id', $company->id)->pluck('id', 'employee_code');
        $devices = Device::query()->where('company_id', $company->id)->get()->keyBy('serial_number');
        $samples = ['errors' => [], 'duplicates' => [], 'unknown' => [], 'non_employee' => [], 'devices' => []];
        $counts = ['rows' => 0, 'new' => 0, 'existing' => 0, 'duplicate_in_file' => 0, 'errors' => 0, 'restaurant' => 0];
        $perDevice = [];
        $perDay = [];
        $suggestedBranches = [];
        $first = null;
        $last = null;
        $pending = [];
        $sampleLimit = (int) config('attendance.preview_sample_limit', 50);
        $flush = function () use (&$pending, &$counts, &$samples, &$perDevice, &$perDay, &$suggestedBranches, &$first, &$last, $company, $employees, $devices, $branches, $sampleLimit): void {
            if ($pending === []) {
                return;
            }

            $deviceNames = [];
            $valid = [];
            foreach ($pending as $row) {
                $values = $row['values'];
                if ($values['serial_number'] !== '' && mb_strlen($values['serial_number']) <= 128 && ! $devices->has($values['serial_number'])) {
                    $deviceNames[hash('sha256', $values['serial_number'])] ??= [$values['serial_number'], $values['device_name']];
                }
                if ($row['errors'] === []) {
                    $row['key'] = $this->key($values['person_code'], $row['punched_at'], $values['punch_state'], $values['serial_number']);
                    $valid[] = $row;
                }
            }
            $newDevices = $this->previewKeys->add('device', array_keys($deviceNames));
            $counts['new_devices'] += count($newDevices);
            foreach (array_keys($newDevices) as $fingerprint) {
                [$serial, $name] = $deviceNames[$fingerprint];
                $this->sample($samples['devices'], ['serial' => $serial, 'name' => $name], $sampleLimit);
            }

            $newKeys = $this->previewKeys->add('natural', array_column($valid, 'key'));
            $local = [];
            $unique = [];
            foreach ($valid as $row) {
                if (! isset($newKeys[$row['key']]) || isset($local[$row['key']])) {
                    $counts['duplicate_in_file']++;
                    $this->sample($samples['duplicates'], ['row' => $row['number'], 'code' => $row['values']['person_code']], $sampleLimit);

                    continue;
                }
                $local[$row['key']] = true;
                $unique[] = $row;
            }
            $existing = collect();
            if ($unique !== []) {
                $codes = array_values(array_unique(array_map(fn (array $row): string => $row['values']['person_code'], $unique)));
                $times = array_column($unique, 'punched_at');
                $existing = DB::table('attendance_transactions')->where('company_id', $company->id)
                    ->whereIn('person_code', $codes)->whereBetween('punched_at', [min($times), max($times)])
                    ->get(['person_code', 'punched_at', 'punch_state', 'serial_number'])
                    ->mapWithKeys(fn ($row): array => [$this->key($row->person_code, $row->punched_at, $row->punch_state, $row->serial_number) => true]);
            }
            $unknown = [];
            $nonEmployees = [];
            foreach ($unique as $row) {
                $values = $row['values'];
                $counts[$existing->has($row['key']) ? 'existing' : 'new']++;
                $day = substr($row['punched_at'], 0, 10);
                $first = $first === null ? $day : min($first, $day);
                $last = $last === null ? $row['punched_at'] : max($last, $row['punched_at']);
                $perDevice[$values['serial_number']] = ($perDevice[$values['serial_number']] ?? 0) + 1;
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                if ($values['punch_state'] === '255') {
                    $counts['restaurant']++;
                }
                $prefix = explode('_', $values['device_name'], 2)[0];
                if ($branch = $branches->match($prefix)) {
                    $suggestedBranches[$branch->id] = true;
                }
                if ($employees->has($values['person_code'])) {
                    continue;
                }
                $nonEmployee = false;
                foreach (config('attendance.non_employee_department_patterns') as $pattern) {
                    if (preg_match($pattern, $values['department_label']) === 1) {
                        $nonEmployee = true;
                        break;
                    }
                }
                $fingerprint = hash('sha256', $values['person_code']);
                if ($nonEmployee) {
                    $nonEmployees[$fingerprint] = $values['person_code'];
                } else {
                    $unknown[$fingerprint] = $values['person_code'];
                }
            }
            foreach (['non_employee' => $nonEmployees, 'unknown' => $unknown] as $category => $codes) {
                $newCodes = $this->previewKeys->add($category, array_keys($codes));
                $counts[$category.'_ids'] += count($newCodes);
                foreach (array_keys($newCodes) as $fingerprint) {
                    $this->sample($samples[$category], ['code' => $codes[$fingerprint]], $sampleLimit);
                }
            }
            $pending = [];
        };
        $counts['unknown_ids'] = 0;
        $counts['non_employee_ids'] = 0;
        $counts['new_devices'] = 0;
        $this->previewKeys->open();
        try {
            foreach ($this->file->rows($file, $mapping) as $row) {
                $counts['rows']++;
                if ($row['errors'] !== []) {
                    $counts['errors']++;
                    $this->sample($samples['errors'], ['row' => $row['number'], 'codes' => $row['errors']], $sampleLimit);
                }
                $pending[] = $row;
                if (count($pending) >= 1000) {
                    $flush();
                }
            }
            $flush();
        } finally {
            $this->previewKeys->close();
        }
        $coverage = $this->coverage($company, $coverage, $first, $sheet['export_at'] ?? $last);
        $prior = ImportBatch::query()->where('company_id', $company->id)->where('file_sha256', $sheet['hash'])->first();
        $result = [
            'hash' => $sheet['hash'], 'headers' => $sheet['headers'], 'header_row' => $sheet['header_row'],
            'export_at' => $sheet['export_at'], 'suggested_coverage' => [
                'from' => $first === null ? null : $first.' 00:00:00',
                'to' => $sheet['export_at'] ?? $last,
                'branch_ids' => array_keys($suggestedBranches),
            ],
            'coverage' => $coverage, 'counts' => $counts, 'per_device' => $perDevice, 'per_day' => $perDay,
            'samples' => $samples, 'already_imported' => $prior?->id,
        ];
        $result['token'] = $prior === null ? $this->token($company, $sheet['hash'], $mapping, $coverage) : null;

        return $result;
    }

    public function commit(Company $company, UploadedFile $file, User $actor, array $mapping, array $coverage, string $token): ImportBatch
    {
        $this->authorize($company, $actor);
        $preview = $this->preview($company, $file, $mapping, $coverage);
        if ($preview['already_imported'] !== null || $preview['token'] === null || ! hash_equals($preview['token'], $token)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.review_again')]);
        }
        if ($preview['coverage']['branch_ids'] === []) {
            throw ValidationException::withMessages(['coverage' => __('messages.attendance_import.coverage_invalid')]);
        }
        $path = Storage::disk('local')->putFileAs('attendance-imports/'.$company->id, $file, bin2hex(random_bytes(16)).'.'.strtolower($file->getClientOriginalExtension()));
        if ($path === false) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.storage_failed')]);
        }
        if (hash_file('sha256', Storage::disk('local')->path($path)) !== $preview['hash']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.review_again')]);
        }
        try {
            return DB::transaction(function () use ($company, $actor, $file, $mapping, $preview, $path): ImportBatch {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                if (ImportBatch::query()->where('company_id', $company->id)->where('file_sha256', $preview['hash'])->exists()) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.already_imported')]);
                }
                $coverage = $preview['coverage'];
                $batch = ImportBatch::create([
                    'company_id' => $company->id, 'uploaded_by' => $actor->id,
                    'file_path' => $path, 'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'file_sha256' => $preview['hash'], 'export_at' => $preview['export_at'],
                    'coverage_from' => $coverage['from'], 'coverage_to' => $coverage['to'],
                    'coverage_branch_ids' => $coverage['branch_ids'], 'mapping' => $mapping,
                    'summary' => [
                        'preview_counts' => $preview['counts'],
                        'samples' => [
                            'errors' => $preview['samples']['errors'],
                            'duplicates' => $preview['samples']['duplicates'],
                            'unknown' => $preview['samples']['unknown'],
                            'non_employee' => $preview['samples']['non_employee'],
                            'devices' => array_map(fn (array $device): array => ['serial' => $device['serial']], $preview['samples']['devices']),
                        ],
                    ],
                ]);
                ImportBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id, 'sequence' => 1, 'state' => 'queued']);
                $approvedBranches = [];
                $seenDevices = Device::query()->where('company_id', $company->id)->where('status', 'approved')
                    ->whereIn('serial_number', array_keys($preview['per_device']))->get();
                foreach ($seenDevices as $device) {
                    $setting = DeviceSetting::query()->where('company_id', $company->id)->where('device_id', $device->id)
                        ->whereDate('effective_from', '<=', substr($coverage['from'], 0, 10))
                        ->orderByDesc('effective_from')->orderByDesc('id')->first();
                    if ($setting !== null && $setting->role !== 'retired') {
                        $approvedBranches[$setting->branch_id] = true;
                    }
                }
                foreach ($coverage['branch_ids'] as $branchId) {
                    DB::table('attendance_coverage_intervals')->insert([
                        'company_id' => $company->id, 'batch_id' => $batch->id, 'branch_id' => $branchId,
                        'coverage_from' => $coverage['from'], 'coverage_to' => $coverage['to'],
                        'kind' => 'declared', 'is_confirmed' => isset($approvedBranches[$branchId]), 'created_at' => now(),
                    ]);
                }
                event(new CoverageChanged($company->id, $coverage['branch_ids'], $coverage['from'], $coverage['to']));
                ImportMapping::query()->updateOrCreate(
                    ['company_id' => $company->id, 'header_signature' => hash('sha256', json_encode($preview['headers'], JSON_THROW_ON_ERROR))],
                    ['headers' => $preview['headers'], 'mapping' => $mapping, 'updated_by' => $actor->id],
                );
                $this->audit->log('attendance.import.committed', $batch, ['batch_id' => $batch->id, 'sha256' => $preview['hash']], $actor);
                ImportTransactionsJob::dispatch($batch->id)->afterCommit();

                return $batch;
            });
        } catch (\Throwable $exception) {
            // A sync worker can fail after the batch commits; its original file must remain recoverable.
            if (! DB::table('attendance_import_batches')->where('company_id', $company->id)->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function key(string $code, string $at, string $state, string $serial): string
    {
        return hash('sha256', json_encode([$code, $at, $state, $serial], JSON_THROW_ON_ERROR));
    }

    private function sample(array &$bucket, array $value, int $limit): void
    {
        if (count($bucket) < $limit) {
            $bucket[] = $value;
        }
    }

    private function coverage(Company $company, array $input, ?string $firstDate, ?string $lastAt): array
    {
        if (! is_array($input['branch_ids'] ?? [])) {
            throw ValidationException::withMessages(['coverage' => __('messages.attendance_import.coverage_invalid')]);
        }
        $from = $this->coverageTime($input['from'] ?? ($firstDate === null ? null : $firstDate.' 00:00:00'));
        $to = $this->coverageTime($input['to'] ?? $lastAt);
        $branches = array_values(array_unique(array_map('intval', $input['branch_ids'] ?? [])));
        if ($from === null || $to === null || $from > $to
            || Branch::query()->where('company_id', $company->id)->whereIn('id', $branches)->count() !== count($branches)) {
            throw ValidationException::withMessages(['coverage' => __('messages.attendance_import.coverage_invalid')]);
        }
        sort($branches);

        return ['from' => $from, 'to' => $to, 'branch_ids' => $branches];
    }

    private function coverageTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $value = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) ? $value.':00' : $value;
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'Asia/Baghdad');

            return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function token(Company $company, string $hash, array $mapping, array $coverage): string
    {
        return hash_hmac('sha256', json_encode([$company->id, auth()->id(), $hash, $mapping, $coverage], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function authorize(Company $company, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor instanceof User && auth()->id() === $actor->id && $actor->is_active
            && in_array($actor->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $actor->canAccessTenant($company)
            && app(TenantContext::class)->companyId() === $company->id, 403);
    }
}
