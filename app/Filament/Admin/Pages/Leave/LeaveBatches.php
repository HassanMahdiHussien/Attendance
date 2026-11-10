<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class LeaveBatches extends LeavePage
{
    protected static ?string $slug = 'leave/batches';

    protected string $view = 'filament.admin.pages.leave.batches';

    #[Url]
    public string $batchId = '';

    private ?LengthAwarePaginator $batchPage = null;

    private array $eventsByBatch = [];

    private array $uploaderNames = [];

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.batches_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function batches(): LengthAwarePaginator
    {
        if ($this->batchPage !== null) {
            return $this->batchPage;
        }
        $this->batchPage = ImportBatch::query()->where('company_id', $this->company()->id)
            ->when($this->batchId !== '', fn ($query) => $query->whereKey(ctype_digit($this->batchId) ? (int) $this->batchId : 0))
            ->latest('id')->paginate(25);
        $batches = $this->batchPage->getCollection();
        $this->eventsByBatch = ImportBatchEvent::query()->where('company_id', $this->company()->id)
            ->whereIn('batch_id', $batches->pluck('id'))->orderByDesc('sequence')->get()->unique('batch_id')->keyBy('batch_id')->all();
        $this->uploaderNames = User::query()->where(fn ($query) => $query->where('company_id', $this->company()->id)
            ->orWhere('primary_role', UserRole::PlatformAdmin->value))
            ->whereIn('id', $batches->pluck('uploaded_by'))->pluck('name', 'id')->all();

        return $this->batchPage;
    }

    public function uploaderName(ImportBatch $batch): ?string
    {
        $this->batches();

        return $this->uploaderNames[$batch->uploaded_by] ?? null;
    }

    public function latestEvent(ImportBatch $batch): ?ImportBatchEvent
    {
        abort_unless((int) $batch->company_id === (int) $this->company()->id, 404);

        if ($this->batchPage !== null && $this->batchPage->getCollection()->contains('id', $batch->id)) {
            return $this->eventsByBatch[$batch->id] ?? null;
        }

        return ImportBatchEvent::query()->where('company_id', $this->company()->id)
            ->where('batch_id', $batch->id)->latest('sequence')->first();
    }

    public function retry(int $batchId): void
    {
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)->findOrFail($batchId);
        abort_unless($this->latestEvent($batch)?->state === 'failed', 403);
        ImportLeaveJob::dispatch($batch->id)->afterCommit();
        Notification::make()->title(__('messages.leave_ui.retry_queued'))->success()->send();
    }
}
