<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Jobs\Attendance\AnalyseTransactionDraftJob;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportDraft;
use App\Models\Attendance\ImportMapping;
use App\Models\Branch;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class UploadAttendance extends AttendancePage
{
    use WithFileUploads;

    protected static ?string $slug = 'attendance/upload';

    protected string $view = 'filament.admin.pages.attendance.upload';

    public $sourceFile;

    public string $step = 'upload';

    public array $headers = [];

    public array $mapping = [];

    public array $coverage = ['from' => '', 'to' => '', 'branch_ids' => []];

    public bool $coverageEdited = false;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public ?string $previewKey = null;

    #[Locked]
    public ?int $batchId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.attendance_ui.upload_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $draft = ImportDraft::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->latest('id')->first();
        // Only the newest upload can be resumed: once it is imported, older failed drafts are history.
        if ($draft !== null && $draft->batch_id === null) {
            $this->draftId = $draft->id;
            $this->headers = $draft->headers;
            $this->mapping = $draft->mapping ?? [];
            $this->step = $draft->mapping === null ? 'mapping' : 'coverage';
            if ($draft->mapping !== null && $draft->status === 'pending') {
                AnalyseTransactionDraftJob::dispatch($draft->id, $draft->revision);
            }

            return;
        }
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->latest('id')->first();
        if ($batch !== null) {
            $this->batchId = $batch->id;
            $this->step = 'progress';
        }
    }

    public function prepare(TransactionFile $file): void
    {
        $company = $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:102400']);
        $sheet = $file->inspect($this->sourceFile);
        $this->headers = $sheet['headers'];
        $this->batchId = null;
        $signature = hash('sha256', json_encode($this->headers, JSON_THROW_ON_ERROR));
        $saved = ImportMapping::query()->where('company_id', $company->id)->where('header_signature', $signature)->first();
        $this->mapping = $saved?->mapping ?? $file->suggest($this->headers);
        $path = Storage::disk('local')->putFileAs('attendance-imports/'.$company->id.'/drafts', $this->sourceFile, bin2hex(random_bytes(16)).'.'.strtolower($this->sourceFile->getClientOriginalExtension()));
        if ($path === false) {
            throw ValidationException::withMessages(['sourceFile' => __('messages.attendance_import.storage_failed')]);
        }
        try {
            $draft = ImportDraft::create([
                'company_id' => $company->id, 'uploaded_by' => auth()->id(), 'file_path' => $path,
                'file_name' => basename(str_replace('\\', '/', $this->sourceFile->getClientOriginalName())),
                'file_sha256' => $sheet['hash'], 'headers' => $sheet['headers'], 'header_row' => $sheet['header_row'],
                'export_at' => $sheet['export_at'], 'mapping' => $saved?->mapping,
                'status' => 'pending', 'revision' => 0, 'rows_processed' => 0,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $this->draftId = $draft->id;
        $this->sourceFile = null;
        $this->coverage = ['from' => '', 'to' => '', 'branch_ids' => []];
        $this->coverageEdited = false;
        $this->clearPreview();
        $this->step = $saved === null ? 'mapping' : 'coverage';
        if ($saved !== null) {
            AnalyseTransactionDraftJob::dispatch($draft->id, $draft->revision);
        }
    }

    public function setCoverageStep(TransactionFile $file): void
    {
        $draft = $this->draft();
        abort_unless($draft !== null, 404);
        $this->mapping = $file->validateMapping($this->mapping, $draft->headers);
        $draft->update(['mapping' => $this->mapping, 'revision' => $draft->revision + 1, 'status' => 'pending', 'analysis' => null, 'rows_processed' => 0, 'error_code' => null]);
        $this->clearPreview();
        $this->step = 'coverage';
        AnalyseTransactionDraftJob::dispatch($draft->id, $draft->revision);
    }

    public function updatedMapping(): void
    {
        $this->company();
        $this->clearPreview();
        $this->step = 'mapping';
        if ($draft = $this->draft()) {
            $draft->update(['revision' => $draft->revision + 1, 'status' => 'pending', 'analysis' => null, 'rows_processed' => 0]);
        }
    }

    public function changeMapping(): void
    {
        $this->company();
        abort_unless($this->draft() !== null, 404);
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedCoverage(): void
    {
        $this->company();
        $this->coverageEdited = true;
        $this->clearPreview();
        if ($this->step === 'preview') {
            $this->step = 'coverage';
        }
    }

    public function draft(): ?ImportDraft
    {
        return $this->draftId === null ? null : ImportDraft::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->whereNull('batch_id')->find($this->draftId);
    }

    public function draftProgress(): ?ImportDraft
    {
        $draft = $this->draft();
        if ($draft?->status === 'ready' && ! $this->coverageEdited && $this->coverage['from'] === '') {
            $suggested = $draft->analysis['suggested_coverage'];
            $this->coverage = $suggested;
            foreach (['from', 'to'] as $field) {
                if ($this->coverage[$field] !== null) {
                    $this->coverage[$field] = str_replace(' ', 'T', substr($this->coverage[$field], 0, 16));
                }
            }
        }

        return $draft;
    }

    public function preview(TransactionImport $import): void
    {
        $this->company();
        // A "wait for analysis" error from an earlier click must not outlive the finished analysis.
        $this->resetErrorBag('coverage');
        $draft = $this->draft();
        if ($draft?->status !== 'ready') {
            throw ValidationException::withMessages(['coverage' => __('messages.attendance_import.analysis_waiting')]);
        }
        $preview = $import->finalise($this->company(), $draft->analysis, $this->coverage);
        $this->clearPreview();
        $this->previewKey = bin2hex(random_bytes(16));
        // The database cache store allows arrays, but not serialized application objects.
        Cache::put($this->cacheKey(), $preview, now()->addMinutes(30));
        $this->step = 'preview';
    }

    // Not named commit: Livewire aliases wire:click="commit" to its own $commit action, so the method would never run.
    public function confirmImport(TransactionImport $import): void
    {
        $this->company();
        $preview = $this->previewData();
        abort_unless($preview !== null && $preview['token'] !== null, 403);
        $draft = $this->draft();
        abort_unless($draft !== null, 404);
        $batch = $import->commitDraft($this->company(), $draft, auth()->user(), $this->coverage, $preview['token']);
        $this->clearPreview();
        $this->sourceFile = null;
        $this->batchId = $batch->id;
        $this->step = 'progress';
        Notification::make()->title(__('messages.attendance_ui.queued'))->success()->send();
    }

    public function previewData(): ?array
    {
        $this->company();
        $cached = $this->previewKey === null ? null : Cache::get($this->cacheKey());

        return is_array($cached) ? $cached : null;
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function unconfirmableBranches(): array
    {
        $approved = Device::query()->where('company_id', $this->company()->id)->where('status', 'approved')->pluck('id');
        $confirmable = DeviceSetting::query()->whereIn('device_id', $approved)->where('role', '!=', 'retired')->pluck('branch_id')->all();

        return array_diff(array_keys($this->branches()), $confirmable);
    }

    public function progress(): ?array
    {
        $this->company();
        if ($this->batchId === null) {
            return null;
        }
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)->where('uploaded_by', auth()->id())->find($this->batchId);
        if ($batch === null) {
            return null;
        }

        return ['batch' => $batch, 'events' => ImportBatchEvent::query()->where('batch_id', $batch->id)->orderByDesc('sequence')->limit(20)->get()];
    }

    public function pendingNewDevicesCount(): int
    {
        if ($this->batchId === null) {
            return 0;
        }

        return Device::query()->where('company_id', $this->company()->id)
            ->where('status', 'pending')->whereIn('id', function ($query): void {
                $query->from('attendance_transactions')->select('device_id')
                    ->where('company_id', $this->company()->id)->where('first_batch_id', $this->batchId);
            })->count();
    }

    private function clearPreview(): void
    {
        if ($this->previewKey !== null) {
            Cache::forget($this->cacheKey());
        }
        $this->previewKey = null;
    }

    private function cacheKey(): string
    {
        return 'attendance-preview:'.$this->company()->id.':'.auth()->id().':'.$this->previewKey;
    }
}
