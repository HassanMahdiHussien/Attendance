<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\ImportMapping;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class UploadLeave extends LeavePage
{
    use WithFileUploads;

    protected static ?string $slug = 'leave/upload';

    protected string $view = 'filament.admin.pages.leave.upload';

    public $sourceFile;

    public string $step = 'upload';

    public array $headers = [];

    public array $mapping = [];

    public array $scope = ['scope' => 'partial', 'period_from' => null, 'period_to' => null, 'snapshot_at' => null];

    #[Locked]
    public ?string $previewKey = null;

    #[Locked]
    public ?int $batchId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.upload_title');
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

    public function prepare(LeaveFile $file, LeaveImport $import): void
    {
        $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        $sheet = $file->inspect($this->sourceFile);
        $this->headers = $sheet['headers'];
        $this->batchId = null;
        $saved = ImportMapping::query()->where('company_id', $this->company()->id)
            ->where('header_signature', $sheet['header_signature'])->first();
        $this->mapping = $saved?->mapping ?? $file->suggest($this->headers);
        $this->scope = ['scope' => 'partial', 'period_from' => null, 'period_to' => null, 'snapshot_at' => $sheet['snapshot_at']];
        $this->clearPreview();
        $this->step = $saved === null ? 'mapping' : 'scope';
        if ($saved !== null) {
            $this->suggestScope($import);
        }
    }

    public function setScopeStep(LeaveFile $file, LeaveImport $import): void
    {
        $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        abort_unless($file->inspect($this->sourceFile)['headers'] === $this->headers, 422);
        $this->mapping = $file->validateMapping($this->mapping, $this->headers);
        $this->clearPreview();
        $this->step = 'scope';
        $this->suggestScope($import);
    }

    public function updatedMapping(): void
    {
        $this->company();
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedScope(): void
    {
        $this->company();
        $this->clearPreview();
        if ($this->step === 'preview') {
            $this->step = 'scope';
        }
    }

    public function suggestScope(LeaveImport $import): void
    {
        $this->company();
        $preview = $import->preview($this->company(), $this->sourceFile, $this->mapping, $this->scope);
        $this->scope['period_from'] = $preview['suggested_scope']['period_from'];
        $this->scope['period_to'] = $preview['suggested_scope']['period_to'];
    }

    public function preview(LeaveImport $import): void
    {
        $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        $preview = $import->preview($this->company(), $this->sourceFile, $this->mapping, $this->scope);
        $this->clearPreview();
        $this->previewKey = bin2hex(random_bytes(16));
        // The database cache store accepts preview arrays, but not serialized application objects.
        Cache::put($this->cacheKey(), $preview, now()->addMinutes(30));
        $this->step = 'preview';
    }

    public function commit(LeaveImport $import): void
    {
        $this->company();
        $preview = $this->previewData();
        abort_unless($preview !== null && $preview['token'] !== null, 403);
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        $batch = $import->commit($this->company(), $this->sourceFile, auth()->user(), $this->mapping, $this->scope, $preview['token']);
        $this->clearPreview();
        $this->sourceFile = null;
        $this->batchId = $batch->id;
        $this->step = 'progress';
        Notification::make()->title(__('messages.leave_ui.queued'))->success()->send();
    }

    public function previewData(): ?array
    {
        $this->company();
        $cached = $this->previewKey === null ? null : Cache::get($this->cacheKey());

        return is_array($cached) ? $cached : null;
    }

    public function progress(): ?array
    {
        $this->company();
        if ($this->batchId === null) {
            return null;
        }
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->find($this->batchId);
        if ($batch === null) {
            return null;
        }

        return ['batch' => $batch, 'events' => ImportBatchEvent::query()->where('company_id', $this->company()->id)
            ->where('batch_id', $batch->id)->orderByDesc('sequence')->limit(20)->get()];
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
        return 'leave-preview:'.$this->company()->id.':'.auth()->id().':'.$this->previewKey;
    }
}
