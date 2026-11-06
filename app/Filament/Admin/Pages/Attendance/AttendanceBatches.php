<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Branch;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class AttendanceBatches extends AttendancePage
{
    protected static ?string $slug = 'attendance/batches';

    protected string $view = 'filament.admin.pages.attendance.batches';

    #[Url]
    public string $batchId = '';

    public ?int $selectedBatch = null;

    public int $errorPage = 1;

    private ?LengthAwarePaginator $batchPage = null;

    private array $eventsByBatch = [];

    private array $uploaderNames = [];

    private array $branchNames = [];

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
        $branchIds = $batches->flatMap(fn (ImportBatch $batch): array => $batch->coverage_branch_ids ?? [])->unique()->all();
        $this->branchNames = Branch::query()->where('company_id', $this->company()->id)
            ->whereIn('id', $branchIds)->pluck('name', 'id')->all();

        return $this->batchPage;
    }

    public function uploaderName(ImportBatch $batch): ?string
    {
        $this->batches();

        return $this->uploaderNames[$batch->uploaded_by] ?? null;
    }

    public function branchNames(ImportBatch $batch): string
    {
        $this->batches();

        return implode(', ', array_values(array_intersect_key($this->branchNames, array_flip($batch->coverage_branch_ids ?? []))));
    }

    public function latestEvent(ImportBatch $batch): ?ImportBatchEvent
    {
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
