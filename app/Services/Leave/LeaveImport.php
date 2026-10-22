<?php

namespace App\Services\Leave;

use App\Enums\UserRole;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Company;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\ImportMapping;
use App\Models\User;
use App\Services\Attendance\PreviewKeys;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LeaveImport
{
    public function __construct(private LeaveFile $file, private LeaveTypes $types, private PreviewKeys $keys, private AuditLogger $audit) {}

    public function preview(Company $company, UploadedFile $file, array $mapping, array $scope): array
    {
        $this->authorize($company);
        $sheet = $this->file->inspect($file);
        $mapping = $this->file->validateMapping($mapping, $sheet['headers']);
        $counts = ['rows' => 0, 'errors' => 0, 'duplicates' => 0, 'unknown_employees' => 0, 'unmapped_types' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0];
        $samples = ['errors' => [], 'duplicates' => [], 'unknown' => []];
        $first = null;
        $last = null;
        $this->keys->open();
        try {
            $chunk = [];
            $flush = function () use (&$chunk, &$counts, &$samples, $company): void {
                if ($chunk === []) {
                    return;
                }
                $ids = array_values(array_unique(array_filter(array_column($chunk, 'external_id'), fn (string $id): bool => $id !== '')));
                $codes = array_values(array_unique(array_filter(array_column($chunk, 'owner_code'), fn (string $code): bool => $code !== '')));
                $existing = DB::table('leave_requests as requests')
                    ->leftJoin('leave_types as types', function ($join): void {
                        $join->on('types.id', '=', 'requests.leave_type_id')->on('types.company_id', '=', 'requests.company_id');
                    })
                    ->leftJoin('leave_request_versions as versions', function ($join): void {
                        $join->on('versions.id', '=', 'requests.current_version_id')->on('versions.company_id', '=', 'requests.company_id');
                    })
                    ->where('requests.company_id', $company->id)->where('requests.source', 'company_report')
                    ->whereIn('requests.external_id', $ids)
                    ->get(['requests.*', 'types.normalized_key as type_key', 'versions.raw_values as prior_raw'])->keyBy('external_id');
                $employees = DB::table('employees')->where('company_id', $company->id)
                    ->whereIn('employee_code', $codes)->pluck('id', 'employee_code');
                $typeKeys = array_values(array_unique(array_map(
                    fn (array $row): string => $this->types->key($row['values']['type_name'], $row['values']['other_type_name']),
                    $chunk,
                )));
                $knownTypes = DB::table('leave_types')->where('company_id', $company->id)
                    ->whereIn('normalized_key', $typeKeys)->pluck('is_mapped', 'normalized_key');
                $new = $this->keys->add('leave', array_map(fn (string $id): string => hash('sha256', $id), $ids));
                $seen = [];
                foreach ($chunk as $row) {
                    $id = $row['external_id'];
                    if ($id !== '') {
                        $key = hash('sha256', $id);
                        if (! isset($new[$key]) || isset($seen[$key])) {
                            $counts['duplicates']++;
                            $this->sample($samples['duplicates'], ['row' => $row['number']], 50);
                        }
                        $seen[$key] = true;
                    }
                    if ($row['owner_code'] !== '' && ! $employees->has($row['owner_code'])) {
                        $counts['unknown_employees']++;
                        $this->sample($samples['unknown'], ['row' => $row['number']], 50);
                    }
                    $typeKey = $this->types->key($row['values']['type_name'], $row['values']['other_type_name']);
                    $mapped = $knownTypes->has($typeKey) ? (bool) $knownTypes->get($typeKey) : $this->types->suggested($typeKey)['mapped'];
                    if (! $mapped) {
                        $counts['unmapped_types']++;
                    }
                    if ($id !== '') {
                        $current = $existing->get($id);
                        if ($current === null) {
                            $counts['new']++;
                        } else {
                            $values = $row['values'];
                            $same = $current->owner_code === $row['owner_code']
                                && (int) $current->employee_id === (int) $employees->get($row['owner_code'])
                                && $current->raw_status === $values['raw_status']
                                && $current->status === $values['status']
                                && $current->type_key === $typeKey
                                && $current->start_date === $values['start_date']
                                && $current->end_date === $values['end_date']
                                && $current->rejected_on === $values['rejected_on']
                                && $current->approver_name === $values['approver_name']
                                && substr((string) $current->created_on, 0, 10) === (string) $values['created_on']
                                && (string) $current->duration_days === ($values['duration_days'] === null ? '' : number_format((float) $values['duration_days'], 1, '.', ''))
                                && json_decode((string) $current->prior_raw, true) == $row['raw'];
                            $counts[$same ? 'unchanged' : 'changed']++;
                        }
                    }
                }
                $chunk = [];
            };
            foreach ($this->file->rows($file, $mapping) as $row) {
                $counts['rows']++;
                if ($row['errors'] !== []) {
                    $counts['errors']++;
                    $this->sample($samples['errors'], ['row' => $row['number'], 'codes' => $row['errors']], 50);
                }
                $date = $row['values']['start_date'];
                if ($date !== null) {
                    $first = $first === null ? $date : min($first, $date);
                    $last = $last === null ? $date : max($last, $date);
                }
                $chunk[] = ['number' => $row['number'], 'external_id' => $row['values']['external_id'], 'owner_code' => $row['values']['owner_code'], 'values' => $row['values'], 'raw' => $row['raw']];
                if (count($chunk) === 1000) {
                    $flush();
                }
            }
            $flush();
        } finally {
            $this->keys->close();
        }
        $scope = $this->scope($scope, $sheet['snapshot_at'], $first, $last);
        $prior = ImportBatch::query()->where('company_id', $company->id)->where('file_sha256', $sheet['hash'])->first();

        return [
            'hash' => $sheet['hash'], 'headers' => $sheet['headers'], 'header_signature' => $sheet['header_signature'],
            'suggested_scope' => ['snapshot_at' => $sheet['snapshot_at'], 'period_from' => $first, 'period_to' => $last],
            'scope' => $scope, 'counts' => $counts, 'samples' => $samples, 'already_imported' => $prior?->id,
            'token' => $prior === null ? $this->token($company, $sheet['hash'], $mapping, $scope) : null,
        ];
    }

    public function commit(Company $company, UploadedFile $file, User $actor, array $mapping, array $scope, string $token): ImportBatch
    {
        $this->authorize($company, $actor);
        $preview = $this->preview($company, $file, $mapping, $scope);
        if ($preview['already_imported'] !== null || $preview['token'] === null || ! hash_equals($preview['token'], $token)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.review_again')]);
        }
        $path = Storage::disk('local')->putFileAs('leave-imports/'.$company->id, $file, bin2hex(random_bytes(16)).'.'.mb_strtolower($file->getClientOriginalExtension()));
        if ($path === false) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.storage_failed')]);
        }
        if (hash_file('sha256', Storage::disk('local')->path($path)) !== $preview['hash']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.review_again')]);
        }
        try {
            return DB::transaction(function () use ($company, $actor, $file, $mapping, $preview, $path): ImportBatch {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                if (ImportBatch::query()->where('company_id', $company->id)->where('file_sha256', $preview['hash'])->exists()) {
                    throw ValidationException::withMessages(['sourceFile' => __('messages.leave_import.already_imported')]);
                }
                $scope = $preview['scope'];
                $batch = ImportBatch::create([
                    'company_id' => $company->id, 'uploaded_by' => $actor->id,
                    'file_path' => $path, 'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'file_sha256' => $preview['hash'], 'snapshot_at' => $scope['snapshot_at'],
                    'scope' => $scope['scope'], 'period_from' => $scope['period_from'], 'period_to' => $scope['period_to'],
                    'mapping' => $mapping, 'summary' => ['preview_counts' => $preview['counts']],
                ]);
                ImportBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id, 'sequence' => 1, 'state' => 'queued']);
                ImportMapping::query()->updateOrCreate(
                    ['company_id' => $company->id, 'header_signature' => $preview['header_signature']],
                    ['headers' => $preview['headers'], 'mapping' => $mapping, 'updated_by' => $actor->id],
                );
                $this->audit->log('leave.import.committed', $batch, ['batch_id' => $batch->id, 'sha256' => $preview['hash']], $actor);
                ImportLeaveJob::dispatch($batch->id)->afterCommit();

                return $batch;
            });
        } catch (\Throwable $exception) {
            if (! DB::table('leave_import_batches')->where('company_id', $company->id)->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function scope(array $scope, ?string $suggestedSnapshot, ?string $first, ?string $last): array
    {
        $kind = $scope['scope'] ?? 'partial';
        $from = $scope['period_from'] ?? $first;
        $to = $scope['period_to'] ?? $last;
        $snapshot = $scope['snapshot_at'] ?? $suggestedSnapshot ?? now('Asia/Baghdad')->format('Y-m-d H:i:s');
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $snapshot, 'Asia/Baghdad');
        } catch (\Throwable) {
            $parsed = false;
        }
        $validDate = function (?string $date): bool {
            if ($date === null) {
                return true;
            }
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
                return false;
            }

            return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
        };
        if (! in_array($kind, ['full', 'partial'], true) || $parsed === false || $parsed->format('Y-m-d H:i:s') !== $snapshot
            || ($kind === 'full' && ($from === null || $to === null || $from > $to))
            || ! $validDate($from) || ! $validDate($to)) {
            throw ValidationException::withMessages(['scope' => __('messages.leave_import.scope_invalid')]);
        }

        return ['scope' => $kind, 'period_from' => $from, 'period_to' => $to, 'snapshot_at' => $snapshot];
    }

    private function token(Company $company, string $hash, array $mapping, array $scope): string
    {
        return hash_hmac('sha256', json_encode([$company->id, auth()->id(), $hash, $mapping, $scope], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function sample(array &$bucket, array $value, int $limit): void
    {
        if (count($bucket) < $limit) {
            $bucket[] = $value;
        }
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
