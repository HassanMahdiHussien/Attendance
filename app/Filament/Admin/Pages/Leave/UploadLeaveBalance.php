<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Models\Leave\BalanceMapping;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveBalanceImport;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class UploadLeaveBalance extends LeavePage
{
    use WithFileUploads;

    protected static ?string $slug = 'leave/balance/upload';

    protected string $view = 'filament.admin.pages.leave.balance-upload';

    public $sourceFile;

    public string $step = 'upload';

    public array $headers = [];

    public array $subheaders = [];

    public array $mapping = [];

    public string $balanceDate = '';

    public int $balanceYear = 2026;

    #[Locked]
    public ?string $previewKey = null;

    #[Locked]
    public ?int $batchId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_balance_ui.upload');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->balanceDate = today('Asia/Baghdad')->toDateString();
        $this->balanceYear = (int) today('Asia/Baghdad')->year;
    }

    public function prepare(LeaveBalanceFile $file): void
    {
        $company = $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        $sheet = $file->inspect($this->sourceFile);
        $this->headers = $sheet['headers'];
        $this->subheaders = $sheet['subheaders'];
        $saved = BalanceMapping::query()->where('company_id', $company->id)
            ->where('header_signature', $sheet['header_signature'])->first();
        $this->mapping = $saved?->mapping ?? $file->suggest($this->headers, $this->subheaders);
        $this->balanceYear = $sheet['suggested_year'] ?? (int) today('Asia/Baghdad')->year;
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedMapping(): void
    {
        $this->company();
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedBalanceDate(): void
    {
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function updatedBalanceYear(): void
    {
        $this->clearPreview();
        $this->step = 'mapping';
    }

    public function preview(LeaveBalanceImport $import): void
    {
        $company = $this->company();
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200',
            'balanceDate' => 'required|date|before_or_equal:'.today('Asia/Baghdad')->toDateString(),
            'balanceYear' => 'required|integer|between:2000,2100']);
        $preview = $import->preview($company, $this->sourceFile, $this->mapping, $this->balanceDate, $this->balanceYear);
        $this->clearPreview();
        $this->previewKey = bin2hex(random_bytes(16));
        Cache::put($this->cacheKey(), $preview, now()->addMinutes(30));
        $this->step = 'preview';
    }

    public function commit(LeaveBalanceImport $import): void
    {
        $company = $this->company();
        $preview = $this->previewData();
        abort_unless($preview !== null && $preview['token'] !== null, 403);
        $this->validate(['sourceFile' => 'required|file|extensions:csv,xlsx|max:51200']);
        $batch = $import->commit($company, $this->sourceFile, auth()->user(), $this->mapping,
            $this->balanceDate, $this->balanceYear, $preview['token']);
        $this->clearPreview();
        $this->sourceFile = null;
        $this->batchId = $batch->id;
        $this->step = 'progress';
        Notification::make()->title(__('messages.leave_balance_ui.queued'))->success()->send();
    }

    public function previewData(): ?array
    {
        $this->company();
        $cached = $this->previewKey === null ? null : Cache::get($this->cacheKey());

        return is_array($cached) ? $cached : null;
    }

    public function progress(): ?array
    {
        $company = $this->company();
        $batch = $this->batchId === null ? null : BalanceBatch::query()->where('company_id', $company->id)->find($this->batchId);
        if ($batch === null) {
            return null;
        }

        return ['batch' => $batch, 'events' => BalanceBatchEvent::query()->where('company_id', $company->id)
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
        return 'leave-balance-preview:'.$this->company()->id.':'.auth()->id().':'.$this->previewKey;
    }
}
