<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Attendance\DataIssue;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Services\Attendance\Reporting\EmployeeFilterSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LeaveRequests extends LeavePage
{
    protected static ?string $slug = 'leave/requests';

    protected string $view = 'filament.admin.pages.leave.requests';

    public string $status = '';

    public string $typeId = '';

    public string $employeeId = '';

    public string $employeeSearch = '';

    public string $from = '';

    public string $to = '';

    public string $flag = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.requests_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function requests(): LengthAwarePaginator
    {
        $query = LeaveRequest::query()->with(['employee', 'leaveType'])
            ->where('company_id', $this->company()->id);
        if ($this->status === 'cancelled') {
            $query->whereExists($this->activeCancellation(...));
        } elseif ($this->status !== '') {
            $query->where('status', $this->status);
            $query->whereNotExists($this->activeCancellation(...));
        }
        if (ctype_digit($this->typeId)) {
            $query->where('leave_type_id', (int) $this->typeId);
        }
        if (ctype_digit($this->employeeId)) {
            $query->where('employee_id', (int) $this->employeeId);
        }
        if ($this->from !== '') {
            $query->whereDate('end_date', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('start_date', '<=', $this->to);
        }
        if ($this->flag === 'unmapped_type') {
            $query->whereHas('leaveType', fn ($types) => $types->where('is_mapped', false));
        } elseif ($this->flag === 'invalid_dates') {
            $query->whereJsonContains('flags', 'invalid_dates');
        } elseif ($this->flag === 'nothing_to_reconcile') {
            $query->whereIn('id', DataIssue::query()->where('company_id', $this->company()->id)
                ->where('type', 'leave_with_attendance_nothing_to_reconcile')->where('status', 'open')
                ->where('subject_type', 'leave_request_day')
                ->selectRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(subject_key, ':request_day:', -1), '|', 1) AS UNSIGNED)"));
        } elseif ($this->flag !== '') {
            $issue = match ($this->flag) {
                'duration_mismatch' => 'leave_duration_mismatch',
                'missing_from_report' => 'leave_request_missing',
                default => null,
            };
            if ($issue !== null) {
                $query->whereIn('id', DataIssue::query()->where('company_id', $this->company()->id)
                    ->where('type', $issue)->where('status', 'open')->where('subject_type', 'leave_request')
                    ->selectRaw("CAST(SUBSTRING_INDEX(subject_key, ':', -1) AS UNSIGNED)"));
            }
        }

        return $query->latest('id')->paginate(25);
    }

    public function types(): array
    {
        return LeaveType::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function searchEmployees(): array
    {
        return $this->employeeId === ''
            ? app(EmployeeFilterSearch::class)->find(auth()->user(), $this->company(), $this->employeeSearch) : [];
    }

    public function updatedEmployeeSearch(): void
    {
        $this->employeeId = '';
    }

    public function chooseEmployee(int $id, EmployeeFilterSearch $search): void
    {
        $selected = $search->selected(auth()->user(), $this->company(), $id);
        $this->employeeId = (string) $selected['id'];
        $this->employeeSearch = $selected['name'].' · '.$selected['code'];
    }

    public function clearEmployee(): void
    {
        $this->company();
        $this->employeeId = '';
        $this->employeeSearch = '';
    }

    public function detailUrl(LeaveRequest $request): string
    {
        abort_unless((int) $request->company_id === (int) $this->company()->id, 404);

        return LeaveRequestDetail::getUrl(panel: 'admin', tenant: $this->company())
            .'?'.http_build_query(['request' => $request->id]);
    }

    public function displayStatus(LeaveRequest $request): string
    {
        $cancelled = DB::table('leave_request_decisions as cancel')
            ->where('cancel.company_id', $this->company()->id)->where('cancel.request_id', $request->id)
            ->where('cancel.decision', 'cancel')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('leave_request_decisions as withdrawal')
                    ->whereColumn('withdrawal.request_id', 'cancel.request_id')
                    ->where('withdrawal.decision', 'withdraw')
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(withdrawal.`values`, '$.decision_id')) AS UNSIGNED) = cancel.id");
            })->exists();

        return $cancelled ? 'cancelled' : $request->status;
    }

    private function activeCancellation(Builder $query): void
    {
        $query->selectRaw('1')->from('leave_request_decisions as cancel')
            ->whereColumn('cancel.company_id', 'leave_requests.company_id')
            ->whereColumn('cancel.request_id', 'leave_requests.id')
            ->where('cancel.decision', 'cancel')
            ->whereNotExists(function (Builder $withdrawal): void {
                $withdrawal->selectRaw('1')->from('leave_request_decisions as withdrawal')
                    ->whereColumn('withdrawal.request_id', 'cancel.request_id')
                    ->where('withdrawal.decision', 'withdraw')
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(withdrawal.`values`, '$.decision_id')) AS UNSIGNED) = cancel.id");
            });
    }
}
