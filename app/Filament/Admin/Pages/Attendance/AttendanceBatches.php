<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AttendanceBatches extends AttendancePage
{
    protected static ?string $slug = 'attendance/batches';

    protected string $view = 'filament.admin.pages.attendance.batches';

    public ?int $selectedBatch = null;

    public int $errorPage = 1;

    public static function getNavigationLabel(): string
    {
        return __('messages.attendance_ui.batches_title');
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
        return ImportBatchEvent::query()->where('company_id', $this->company()->id)
            ->where('batch_id', $batch->id)->latest('sequence')->first();
    }

    public function retry(int $batchId): void
    {
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)->findOrFail($batchId);
        abort_unless($this->latestEvent($batch)?->state === 'failed', 403);
        ImportTransactionsJob::dispatch($batch->id)->afterCommit();
        Notification::make()->title(__('messages.attendance_ui.retry_queued'))->success()->send();
    }

    public function showErrors(int $batchId): void
    {
        ImportBatch::query()->where('company_id', $this->company()->id)->findOrFail($batchId);
        $this->selectedBatch = $batchId;
        $this->errorPage = 1;
    }

    public function errorSamples(): array
    {
        if ($this->selectedBatch === null) {
            return ['rows' => [], 'pages' => 0];
        }
        $batch = ImportBatch::query()->where('company_id', $this->company()->id)->findOrFail($this->selectedBatch);
        $errors = $batch->summary['samples']['errors'] ?? [];
        $pages = (int) ceil(count($errors) / 10);
        $page = max(1, min($this->errorPage, max(1, $pages)));

        return ['rows' => array_slice($errors, ($page - 1) * 10, 10), 'pages' => $pages];
    }
}
