<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportMapping;
use App\Models\Branch;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
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
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->latest('id')->first();
        if ($batch !== null) {
            $this->batchId = $batch->id;
            $this->step = 'progress';
        }
    }

    public function prepare(TransactionFile $file, TransactionImport $import): void
    {
        $company = $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:102400']);
        $sheet = $file->inspect($this->sourceFile);
        $this->headers = $sheet['headers'];
        $this->batchId = null;
        $signature = hash('sha256', json_encode($this->headers, JSON_THROW_ON_ERROR));
        $saved = ImportMapping::query()->where('company_id', $company->id)->where('header_signature', $signature)->first();
        $this->mapping = $saved?->mapping ?? $file->suggest($this->headers);
        $this->clearPreview();
        $this->step = $saved === null ? 'mapping' : 'coverage';
        if ($saved !== null) {
            $this->suggestCoverage($import);
        }
    }

    public function setCoverageStep(TransactionFile $file, TransactionImport $import): void
    {
        $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:102400']);
        abort_unless($file->inspect($this->sourceFile)['headers'] === $this->headers, 422);
        $this->mapping = $file->validateMapping($this->mapping, $this->headers);
        $this->clearPreview();
        $this->step = 'coverage';
        $this->suggestCoverage($import);
    }

    public function updatedMapping(): void
    {
        $this->company();
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedCoverage(): void
    {
        $this->company();
        $this->clearPreview();
        if ($this->step === 'preview') {
            $this->step = 'coverage';
        }
    }

    public function suggestCoverage(TransactionImport $import): void
    {
        $this->company();
        $preview = $import->preview($this->company(), $this->sourceFile, $this->mapping, ['branch_ids' => []]);
        $this->coverage = $preview['suggested_coverage'];
        foreach (['from', 'to'] as $field) {
            if ($this->coverage[$field] !== null) {
                $this->coverage[$field] = str_replace(' ', 'T', substr($this->coverage[$field], 0, 16));
            }
        }
    }

    public function preview(TransactionImport $import): void
    {
        $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:102400']);
        $preview = $import->preview($this->company(), $this->sourceFile, $this->mapping, $this->coverage);
        $this->clearPreview();
        $this->previewKey = bin2hex(random_bytes(16));
        // The database cache store allows arrays, but not serialized application objects.
        Cache::put($this->cacheKey(), $preview, now()->addMinutes(30));
        $this->step = 'preview';
    }

    public function commit(TransactionImport $import): void
    {
        $this->company();
        $preview = $this->previewData();
        abort_unless($preview !== null && $preview['token'] !== null, 403);
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:102400']);
        $batch = $import->commit($this->company(), $this->sourceFile, auth()->user(), $this->mapping, $this->coverage, $preview['token']);
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
