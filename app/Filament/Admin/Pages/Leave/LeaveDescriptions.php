<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Jobs\Leave\ReadLeaveDescriptionsJob;
use App\Models\Branch;
use App\Models\Leave\DescriptionReading;
use App\Models\Leave\EntitlementConsumption;
use App\Services\Leave\LeaveDescriptionDecisions;
use App\Services\Leave\LeaveDescriptionEffects;
use App\Services\Leave\LeaveDescriptionReader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LeaveDescriptions extends LeavePage
{
    protected static ?string $slug = 'leave/descriptions';

    protected string $view = 'filament.admin.pages.leave.descriptions';

    public string $category = '';

    public string $status = '';

    public string $branchId = '';

    public string $from = '';

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
            ->where('company_id', $this->company()->id);
        if (in_array($this->category, LeaveDescriptionReader::CATEGORIES, true)) {
            $query->where('category', $this->category);
        }
        if (in_array($this->status, ['proposed', 'confirmed', 'rejected', 'not_needed', 'failed'], true)) {
            $this->status === 'failed' ? $query->whereNotNull('error_code') : $query->where('status', $this->status);
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

    public function effectReason(DescriptionReading $reading): ?string
    {
        return $reading->category === null ? 'information_only'
            : app(LeaveDescriptionEffects::class)->reason($reading->request, $reading);
    }

    public function currentCreditDate(DescriptionReading $reading): ?string
    {
        if ($reading->category !== 'compensation_for_day' || $reading->status !== 'proposed') {
            return null;
        }

        return EntitlementConsumption::query()->with('entitlement')
            ->where('company_id', $this->company()->id)->where('request_id', $reading->request_id)
            ->whereNull('superseded_by_run_id')->orderBy('id')->first()?->entitlement?->work_date?->toDateString();
    }

    public function hourlyPreview(DescriptionReading $reading): array
    {
        return app(LeaveDescriptionEffects::class)->hourlyPreview($reading->request, $reading);
    }

    public function counts(): array
    {
        $query = DescriptionReading::query()->where('company_id', $this->company()->id);

        return [
            'categories' => (clone $query)->selectRaw('category, COUNT(*) AS total')->groupBy('category')->pluck('total', 'category')->all(),
            'statuses' => (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'failures' => (clone $query)->whereNotNull('error_code')->count(),
            'total' => (clone $query)->count(),
            'read' => (clone $query)->whereNotNull('category')->count(),
        ];
    }

    public function confirmableCount(): int
    {
        return (clone $this->filtered())->where('status', 'proposed')->where('confidence', 'high')
            ->whereNull('error_code')->get()->filter(fn (DescriptionReading $reading): bool => $this->effectReason($reading) === null)->count();
    }

    public function decide(int $id, string $decision, LeaveDescriptionDecisions $service): void
    {
        $reading = DescriptionReading::query()->where('company_id', $this->company()->id)->findOrFail($id);
        $service->decide($reading, $decision, auth()->user());
    }

    public function confirmAll(LeaveDescriptionDecisions $service): void
    {
        $ids = (clone $this->filtered())->where('status', 'proposed')->where('confidence', 'high')
            ->whereNull('error_code')->get()->filter(fn (DescriptionReading $reading): bool => $this->effectReason($reading) === null)
            ->pluck('id')->all();
        $service->confirmAll($this->company(), $ids, auth()->user());
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
