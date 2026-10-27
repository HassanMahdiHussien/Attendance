<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\DataIssue;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Services\Attendance\DataIssues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class AttendanceIssues extends AttendancePage
{
    use WithPagination;

    protected static ?string $slug = 'attendance/issues';

    protected string $view = 'filament.admin.pages.attendance.issues';

    #[Url]
    public string $type = '';

    #[Url]
    public string $subjectKey = '';

    public string $status = 'open';

    public array $notes = [];

    public array $selectedIssueIds = [];

    public string $bulkNote = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.attendance_ui.issues_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function issues(): LengthAwarePaginator
    {
        return DataIssue::query()->where('company_id', $this->company()->id)
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->subjectKey !== '', fn ($query) => $query->where('subject_key', $this->subjectKey))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('id')->paginate(25);
    }

    public function typeCounts(): Collection
    {
        return DataIssue::query()->where('company_id', $this->company()->id)
            ->when($this->subjectKey !== '', fn ($query) => $query->where('subject_key', $this->subjectKey))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->selectRaw('type, count(*) as issue_count')->groupBy('type')->orderBy('type')->get();
    }

    public function types(): Collection
    {
        return DataIssue::query()->where('company_id', $this->company()->id)
            ->distinct()->orderBy('type')->pluck('type');
    }

    public function showType(string $type): void
    {
        abort_unless($this->types()->contains($type), 404);
        $this->type = $type;
        $this->selectedIssueIds = [];
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->selectedIssueIds = [];
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->selectedIssueIds = [];
        $this->resetPage();
    }

    public function selectAllOnPage(): void
    {
        abort_if($this->type === '' || $this->status !== 'open', 403);
        $this->selectedIssueIds = collect($this->issues()->items())->pluck('id')->all();
    }

    public function bulkClose(string $action, DataIssues $issues): void
    {
        abort_unless($this->type !== '' && $this->status === 'open' && in_array($action, ['resolve', 'dismiss'], true), 403);
        $this->validate([
            'selectedIssueIds' => 'required|array|min:1',
            'selectedIssueIds.*' => 'required|integer|distinct',
            'bulkNote' => 'required|string|max:1000',
        ]);
        $pageIds = collect($this->issues()->items())->pluck('id');
        abort_unless(collect($this->selectedIssueIds)->every(fn ($id): bool => $pageIds->contains((int) $id)), 404);
        $selected = DataIssue::query()->where('company_id', $this->company()->id)
            ->where('type', $this->type)->where('status', 'open')->whereIn('id', $this->selectedIssueIds)->get();
        abort_unless($selected->count() === count($this->selectedIssueIds), 404);
        abort_if($action === 'resolve' && $selected->contains('type', 'pattern_review'), 403);
        foreach ($selected as $issue) {
            $issues->{$action}($issue, auth()->user(), $this->bulkNote);
        }
        $this->selectedIssueIds = [];
        $this->bulkNote = '';
    }

    public function employeeSubject(DataIssue $issue): ?Employee
    {
        if (! in_array($issue->subject_type, ['employee', 'manager'], true)
            || ! preg_match('/^(?:employee|manager):(\d+)$/', $issue->subject_key, $matches)) {
            return null;
        }

        return Employee::query()->where('company_id', $this->company()->id)->find((int) $matches[1]);
    }

    public function leaveRequestSubject(DataIssue $issue): ?LeaveRequest
    {
        if ($issue->subject_type !== 'leave_request' || ! preg_match('/^request:(\d+)$/', $issue->subject_key, $matches)) {
            return null;
        }

        return LeaveRequest::query()->where('company_id', $this->company()->id)->find((int) $matches[1]);
    }

    public function close(int $issueId, string $action, DataIssues $issues): void
    {
        $issue = DataIssue::query()->where('company_id', $this->company()->id)->findOrFail($issueId);
        abort_unless($issue->status === 'open' && in_array($action, ['resolve', 'dismiss'], true), 403);
        abort_if($issue->type === 'pattern_review' && $action === 'resolve', 403);
        $this->validate(['notes.'.$issueId => 'required|string|max:1000']);
        $issues->{$action}($issue, auth()->user(), $this->notes[$issueId]);
        unset($this->notes[$issueId]);
    }
}
