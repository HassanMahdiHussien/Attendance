<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Attendance\DataIssue;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\RequestVersion;
use App\Services\Leave\LeaveActions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class LeaveRequestDetail extends LeavePage
{
    protected static ?string $slug = 'leave/request';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.leave.request-detail';

    #[Locked]
    public ?int $requestId = null;

    public string $reason = '';

    public string $distributionMode = 'from_start';

    public string $distributionDates = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $durationDays = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.detail_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $request = request()->query('request');
        abort_unless((is_string($request) || is_int($request)) && ctype_digit((string) $request), 404);
        $this->requestId = (int) $request;
        $record = $this->record();
        $this->startDate = $record->start_date?->toDateString() ?? '';
        $this->endDate = $record->end_date?->toDateString() ?? '';
        $this->durationDays = (string) $record->duration_days;
    }

    public function record(): LeaveRequest
    {
        return LeaveRequest::query()->with(['employee', 'leaveType'])->where('company_id', $this->company()->id)
            ->findOrFail($this->requestId);
    }

    public function versions(): LengthAwarePaginator
    {
        return RequestVersion::query()->with('batch')->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->orderByDesc('snapshot_at')->orderByDesc('batch_id')->paginate(20);
    }

    public function days(): array
    {
        return RequestDay::query()->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->whereNull('superseded_by_run_id')
            ->orderBy('work_date')->get()->all();
    }

    public function allocations(): array
    {
        $dayIds = RequestDay::query()->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->pluck('id');
        $creditIds = Credit::query()->where('company_id', $this->company()->id)
            ->whereIn('source_day_id', $dayIds)->pluck('id');

        return Allocation::query()->with(['targetDay', 'sourceCredit'])->where('company_id', $this->company()->id)
            ->whereNull('superseded_by_run_id')
            ->where(fn ($query) => $query->whereIn('source_request_day_id', $dayIds)->orWhereIn('source_credit_id', $creditIds))
            ->orderBy('id')->get()->all();
    }

    public function credits(): array
    {
        $dayIds = RequestDay::query()->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->pluck('id');

        return Credit::query()->where('company_id', $this->company()->id)
            ->whereIn('source_day_id', $dayIds)->whereNull('superseded_by_run_id')->get()->all();
    }

    public function decisions(): array
    {
        return RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->latest('id')->get()->all();
    }

    public function issues(): array
    {
        $request = $this->record();

        return DataIssue::query()->where('company_id', $this->company()->id)->where('status', 'open')
            ->where(function ($query) use ($request): void {
                $query->where(function ($requests) use ($request): void {
                    $requests->where('subject_type', 'leave_request')->where('subject_key', 'request:'.$request->id);
                })->orWhere(function ($days) use ($request): void {
                    $days->where('subject_type', 'leave_request_day')
                        ->where('subject_key', 'like', '%:request_day:'.$request->id.'|%');
                });
            })->orderBy('id')->get()->all();
    }

    public function effectiveValues(): array
    {
        $request = $this->record();
        $decisions = RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('request_id', $request->id)->orderBy('id')->get();
        $withdrawn = $decisions->where('decision', 'withdraw')
            ->map(fn (RequestDecision $decision): int => (int) ($decision->values['decision_id'] ?? 0))->all();
        $active = $decisions->reject(fn (RequestDecision $decision): bool => $decision->decision === 'withdraw'
            || in_array($decision->id, $withdrawn, true));
        $fix = $active->last(fn (RequestDecision $decision): bool => $decision->decision === 'fix_dates');

        return [
            'status' => $active->contains('decision', 'cancel') ? 'cancelled' : $request->status,
            'start_date' => $fix?->values['start_date'] ?? $request->start_date?->toDateString(),
            'end_date' => $fix?->values['end_date'] ?? $request->end_date?->toDateString(),
            'duration_days' => $fix?->values['duration_days'] ?? $request->duration_days,
        ];
    }

    public function isWithdrawn(RequestDecision $decision): bool
    {
        abort_unless((int) $decision->company_id === (int) $this->company()->id
            && (int) $decision->request_id === (int) $this->record()->id, 404);

        return RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('request_id', $decision->request_id)->where('decision', 'withdraw')
            ->where('values->decision_id', $decision->id)->exists();
    }

    public function changedFields(RequestVersion $version): array
    {
        abort_unless((int) $version->company_id === (int) $this->company()->id
            && (int) $version->request_id === (int) $this->record()->id, 404);
        $previous = RequestVersion::query()->where('company_id', $this->company()->id)
            ->where('request_id', $version->request_id)->where('id', '<', $version->id)->latest('id')->first();

        $changed = [];
        foreach ($version->values ?? [] as $field => $value) {
            if (json_encode($value, JSON_THROW_ON_ERROR) !== json_encode($previous?->values[$field] ?? null, JSON_THROW_ON_ERROR)) {
                $changed[] = __('messages.leave_ui.fields.'.$field);
            }
        }
        if ($previous !== null) {
            foreach ($version->raw_values ?? [] as $column => $value) {
                if ($value !== ($previous->raw_values[$column] ?? null)) {
                    $changed[] = __('messages.leave_ui.source_column', ['column' => (int) $column + 1]);
                }
            }
        }

        return array_values(array_unique($changed));
    }

    public function cancel(LeaveActions $actions): void
    {
        $this->validate(['reason' => 'required|string|max:2000']);
        $actions->cancel($this->record(), $this->reason, auth()->user());
        $this->reason = '';
    }

    public function confirmDistribution(LeaveActions $actions): void
    {
        $this->validate(['reason' => 'required|string|max:2000', 'distributionMode' => 'required|in:from_start,dates']);
        $dates = $this->distributionMode === 'dates' ? array_values(array_filter(array_map('trim', explode(',', $this->distributionDates)))) : null;
        $actions->confirmDistribution($this->record(), $this->distributionMode, $dates, $this->reason, auth()->user());
        $this->reason = '';
    }

    public function fixDates(LeaveActions $actions): void
    {
        $this->validate(['reason' => 'required|string|max:2000', 'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d', 'durationDays' => 'required|numeric|min:0.5']);
        $actions->fixDates($this->record(), $this->startDate, $this->endDate, (float) $this->durationDays, $this->reason, auth()->user());
        $this->reason = '';
    }

    public function withdraw(int $decisionId, LeaveActions $actions): void
    {
        $this->validate(['reason' => 'required|string|max:2000']);
        $decision = RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('request_id', $this->record()->id)->findOrFail($decisionId);
        $actions->withdraw($decision, $this->reason, auth()->user());
        $this->reason = '';
    }

    public function creditUsed(int $creditId): int
    {
        return (int) DB::table('leave_allocations')->where('company_id', $this->company()->id)
            ->where('source_credit_id', $creditId)->whereNull('superseded_by_run_id')->sum('minutes');
    }
}
