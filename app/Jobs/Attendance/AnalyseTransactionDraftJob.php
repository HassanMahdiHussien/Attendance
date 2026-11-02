<?php

namespace App\Jobs\Attendance;

use App\Models\Attendance\ImportDraft;
use App\Models\Company;
use App\Services\Attendance\TransactionImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AnalyseTransactionDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public int $draftId, public int $revision) {}

    public function uniqueId(): string
    {
        return $this->draftId.':'.$this->revision;
    }

    public function handle(TransactionImport $import): void
    {
        $companyId = DB::table('attendance_import_drafts')->where('id', $this->draftId)->value('company_id');
        if ($companyId === null) {
            return;
        }
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $import): void {
            $draft = ImportDraft::query()->find($this->draftId);
            if ($draft === null || $draft->revision !== $this->revision || $draft->status !== 'pending') {
                return;
            }
            $draft->update(['status' => 'analysing']);
            try {
                $path = Storage::disk('local')->path($draft->file_path);
                if (! is_file($path) || hash_file('sha256', $path) !== $draft->file_sha256) {
                    throw new RuntimeException('stored_file_unavailable');
                }
                $analysis = $import->analyse($company, $path, $draft->mapping, $draft->file_name, function (int $rows): void {
                    ImportDraft::query()->whereKey($this->draftId)->where('revision', $this->revision)->update(['rows_processed' => $rows]);
                });
                ImportDraft::query()->whereKey($this->draftId)->where('revision', $this->revision)->update([
                    'status' => 'ready', 'analysis' => $analysis, 'rows_processed' => $analysis['counts']['rows'],
                ]);
            } catch (\Throwable $exception) {
                $code = $exception instanceof ValidationException ? 'invalid_file'
                    : ($exception instanceof RuntimeException && $exception->getMessage() === 'stored_file_unavailable' ? 'stored_file_unavailable' : 'processing_failed');
                ImportDraft::query()->whereKey($this->draftId)->where('revision', $this->revision)->update(['status' => 'failed', 'error_code' => $code]);
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('attendance_import_drafts')->where('id', $this->draftId)->where('revision', $this->revision)
            ->whereIn('status', ['pending', 'analysing'])->update(['status' => 'failed', 'error_code' => 'processing_failed', 'updated_at' => now()]);
    }
}
