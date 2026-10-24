<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Models\Leave\BalanceRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

class LeaveBalanceBatches extends LeavePage
{
    protected static ?string $slug = 'leave/balance/uploads';

    protected string $view = 'filament.admin.pages.leave.balance-batches';

    #[Locked]
    public ?int $selectedBatchId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_balance_ui.uploads');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function batches(): LengthAwarePaginator
    {
        return BalanceBatch::query()->where('company_id', $this->company()->id)->orderByDesc('id')->paginate(25);
    }

    public function latestEvent(BalanceBatch $batch): ?BalanceBatchEvent
    {
        abort_unless((int) $batch->company_id === (int) $this->company()->id, 404);

        return BalanceBatchEvent::query()->where('company_id', $this->company()->id)
            ->where('batch_id', $batch->id)->orderByDesc('sequence')->first();
    }

    public function showBatch(int $id): void
    {
        $this->selectedBatchId = BalanceBatch::query()->where('company_id', $this->company()->id)->findOrFail($id)->id;
    }

    public function selectedBatch(): ?BalanceBatch
    {
        return $this->selectedBatchId === null ? null : BalanceBatch::query()
            ->where('company_id', $this->company()->id)->findOrFail($this->selectedBatchId);
    }

    public function skippedRows(): LengthAwarePaginator
    {
        $batch = $this->selectedBatch();
        abort_unless($batch !== null, 404);

        return BalanceRow::query()->where('company_id', $this->company()->id)
            ->where('batch_id', $batch->id)->where('outcome', '!=', 'imported')->orderBy('row_number')->paginate(50, pageName: 'skipped');
    }
}
