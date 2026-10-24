<?php

namespace App\Services\Leave;

use App\Enums\UserRole;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Models\Leave\BalanceMapping;
use App\Models\User;
use App\Services\Attendance\PreviewKeys;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LeaveBalanceImport
{
    public function __construct(private LeaveBalanceFile $file, private PreviewKeys $keys, private AuditLogger $audit) {}

    public function preview(Company $company, UploadedFile $file, array $mapping, string $balanceDate, int $balanceYear): array
    {
        $this->authorize($company);
        $sheet = $this->file->inspect($file);
        $mapping = $this->file->validateMapping($mapping, $sheet['headers']);
        $this->validateDate($balanceDate, $balanceYear);
        $codes = DB::table('employees')->where('company_id', $company->id)->selectRaw('CHAR_LENGTH(employee_code) AS code_length, COUNT(*) AS total')
            ->groupBy('code_length')->orderByDesc('total')->value('code_length');
        $counts = ['rows' => 0, 'matched' => 0, 'unknown' => 0, 'duplicate' => 0, 'invalid' => 0,
            'negative' => 0, 'changed' => 0, 'unchanged' => 0, 'missing' => 0];
        $samples = ['unknown' => [], 'duplicate' => [], 'invalid' => []];
        $this->keys->open();
        try {
            foreach ($this->file->rows($file, $mapping, codeLength: $codes === null ? null : (int) $codes) as $row) {
                $counts['rows']++;
                $code = $row['values']['employee_code'];
                if ($row['errors'] !== []) {
                    $counts['invalid']++;
                    $this->sample($samples['invalid'], ['row' => $row['number'], 'code' => $code, 'reasons' => $row['errors']]);

                    continue;
                }
                $fingerprint = hash('sha256', $code);
                if ($this->keys->add('balance', [$fingerprint]) === []) {
                    $counts['duplicate']++;
                    $this->sample($samples['duplicate'], ['row' => $row['number'], 'code' => $code]);

                    continue;
                }
                $employee = DB::table('employees')->where('company_id', $company->id)->where('employee_code', $code)->first(['id']);
                if ($employee === null) {
                    $counts['unknown']++;
                    $this->sample($samples['unknown'], ['row' => $row['number'], 'code' => $code]);

                    continue;
                }
                $counts['matched']++;
                if ((float) $row['values']['remaining_days'] < 0) {
                    $counts['negative']++;
                }
                $prior = app(LeaveBalances::class)->current(Employee::query()->findOrFail($employee->id));
                $counts[$prior !== null && (string) $prior->remaining_days === $row['values']['remaining_days'] ? 'unchanged' : 'changed']++;
            }
            $counts['missing'] = DB::table('employees')->where('company_id', $company->id)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('attendance_preview_keys as seen')
                        ->where('seen.category', 'balance')
                        ->whereRaw('seen.fingerprint = SHA2(employees.employee_code, 256)');
                })->count();
        } finally {
            $this->keys->close();
        }
        $prior = BalanceBatch::query()->where('company_id', $company->id)->where('file_sha256', $sheet['hash'])
            ->whereDate('balance_date', $balanceDate)->first();

        return ['hash' => $sheet['hash'], 'headers' => $sheet['headers'], 'subheaders' => $sheet['subheaders'],
            'header_signature' => $sheet['header_signature'], 'balance_date' => $balanceDate,
            'balance_year' => $balanceYear, 'counts' => $counts, 'samples' => $samples,
            'already_imported' => $prior?->id,
            'token' => $prior === null ? $this->token($company, $sheet['hash'], $mapping, $balanceDate, $balanceYear) : null];
    }

    public function commit(Company $company, UploadedFile $file, User $actor, array $mapping, string $balanceDate, int $balanceYear, string $token): BalanceBatch
    {
        $this->authorize($company, $actor);
        $preview = $this->preview($company, $file, $mapping, $balanceDate, $balanceYear);
        $mapping = $this->file->validateMapping($mapping, $preview['headers']);
        if (! hash_equals($this->token($company, $preview['hash'], $mapping, $balanceDate, $balanceYear), $token)) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.review_again')]);
        }
        if ($preview['already_imported'] !== null) {
            return BalanceBatch::findOrFail($preview['already_imported']);
        }
        $path = Storage::disk('local')->putFileAs('leave-balances/'.$company->id, $file, bin2hex(random_bytes(16)).'.'.mb_strtolower($file->getClientOriginalExtension()));
        if ($path === false) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.storage_failed')]);
        }
        if (hash_file('sha256', Storage::disk('local')->path($path)) !== $preview['hash']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['sourceFile' => __('messages.leave_balance.review_again')]);
        }
        try {
            $batch = DB::transaction(function () use ($company, $actor, $file, $mapping, $preview, $path): BalanceBatch {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $prior = BalanceBatch::query()->where('company_id', $company->id)->where('file_sha256', $preview['hash'])
                    ->whereDate('balance_date', $preview['balance_date'])->first();
                if ($prior !== null) {
                    return $prior;
                }
                $batch = BalanceBatch::create([
                    'company_id' => $company->id, 'uploaded_by' => $actor->id,
                    'file_path' => $path, 'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'file_sha256' => $preview['hash'], 'balance_date' => $preview['balance_date'],
                    'balance_year' => $preview['balance_year'], 'mapping' => $mapping,
                ]);
                BalanceBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id,
                    'sequence' => 1, 'state' => 'queued', 'counts' => $preview['counts']]);
                BalanceMapping::query()->updateOrCreate(
                    ['company_id' => $company->id, 'header_signature' => $preview['header_signature']],
                    ['headers' => [$preview['headers'], $preview['subheaders']], 'mapping' => $mapping, 'updated_by' => $actor->id],
                );
                $this->audit->log('leave.balance.uploaded', $batch, ['batch_id' => $batch->id, 'sha256' => $preview['hash']], $actor);
                $this->audit->log('leave.balance.committed', $batch, ['batch_id' => $batch->id, 'counts' => $preview['counts']], $actor);
                ImportLeaveBalanceJob::dispatch($batch->id)->afterCommit();

                return $batch;
            });
            if ($batch->file_path !== $path) {
                Storage::disk('local')->delete($path);
            }

            return $batch;
        } catch (\Throwable $exception) {
            if (! DB::table('leave_balance_batches')->where('company_id', $company->id)->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function validateDate(string $balanceDate, int $balanceYear): void
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $balanceDate, 'Asia/Baghdad');
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->toDateString() !== $balanceDate || $date->isAfter(today('Asia/Baghdad'))
            || $balanceYear < 2000 || $balanceYear > (int) today('Asia/Baghdad')->year + 1) {
            throw ValidationException::withMessages(['balanceDate' => __('messages.leave_balance.date_invalid')]);
        }
    }

    private function token(Company $company, string $hash, array $mapping, string $date, int $year): string
    {
        return hash_hmac('sha256', json_encode([$company->id, auth()->id(), $hash, $mapping, $date, $year], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function sample(array &$bucket, array $sample): void
    {
        if (count($bucket) < 50) {
            $bucket[] = $sample;
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
