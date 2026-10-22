<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveBatches extends LeavePage
{
    protected static ?string $slug = 'leave/batches';

    protected string $view = 'filament.admin.pages.leave.batches';

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
        return ImportBatch::query()->where('company_id', $this->company()->id)->latest('id')->paginate(25);
    }

    public function latestEvent(ImportBatch $batch): ?ImportBatchEvent
    {
        abort_unless((int) $batch->company_id === (int) $this->company()->id, 404);

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
