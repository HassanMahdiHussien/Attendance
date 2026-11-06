<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Jobs\Leave\ReadLeaveDescriptionsJob;
use App\Models\Branch;
use App\Models\Leave\DescriptionReading;
use App\Services\Leave\LeaveDescriptionDecisions;
use App\Services\Leave\LeaveDescriptionReader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class LeaveDescriptions extends LeavePage
{
    protected static ?string $slug = 'leave/descriptions';

    protected string $view = 'filament.admin.pages.leave.descriptions';

    #[Url]
    public string $category = '';

    #[Url]
    public string $branchId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_descriptions.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    private function filtered(): Builder
    {
        $query = DescriptionReading::query()->with(['request.employee', 'request.leaveType', 'request.currentVersion'])
            ->where('company_id', $this->company()->id)->whereHas('request',
                fn (Builder $requests) => $requests->where('status', 'approved'));
        if (in_array($this->category, LeaveDescriptionReader::CATEGORIES, true)) {
            $query->where('category', $this->category);
        }
        $query->whereHas('request', function (Builder $requests): void {
            if (ctype_digit($this->branchId)) {
                $requests->whereHas('employee', fn (Builder $employees) => $employees->where('branch_id', (int) $this->branchId));
            }
            if ($this->from !== '') {
                $requests->whereDate('end_date', '>=', $this->from);
            }
            if ($this->to !== '') {
                $requests->whereDate('start_date', '<=', $this->to);
            }
        });

        return $query;
    }

    public function readings(): LengthAwarePaginator
    {
        return $this->filtered()->orderByDesc('id')->paginate(25);
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function categories(): array
    {
        return LeaveDescriptionReader::CATEGORIES;
    }

    public function counts(): array
    {
        $companyId = $this->company()->id;
        $query = DescriptionReading::query()->where('company_id', $companyId)
            ->whereHas('request', fn (Builder $requests) => $requests->where('status', 'approved'));

        return [
            'categories' => (clone $query)->selectRaw('category, COUNT(*) AS total')->groupBy('category')->pluck('total', 'category')->all(),
            'failures' => DescriptionReading::query()->where('company_id', $companyId)->whereNotNull('error_code')->count(),
            'total' => (clone $query)->count(),
        ];
    }

    public function undo(int $id, LeaveDescriptionDecisions $service): void
    {
        $reading = DescriptionReading::query()->where('company_id', $this->company()->id)->findOrFail($id);
        $service->decide($reading, 'proposed', auth()->user());
    }

    public function retry(int $id, LeaveDescriptionReader $reader): void
    {
        $reading = DescriptionReading::query()->where('company_id', $this->company()->id)->findOrFail($id);
        $reader->retry($reading);
        ReadLeaveDescriptionsJob::dispatch($this->company()->id);
    }

    public function retryAll(LeaveDescriptionReader $reader): void
    {
        if ($reader->retryAll($this->company()) > 0) {
            ReadLeaveDescriptionsJob::dispatch($this->company()->id);
        }
    }
}
