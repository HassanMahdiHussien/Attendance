<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\DataIssue;
use App\Services\Attendance\DataIssues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class AttendanceIssues extends AttendancePage
{
    protected static ?string $slug = 'attendance/issues';

    protected string $view = 'filament.admin.pages.attendance.issues';

    #[Url]
    public string $type = '';

    #[Url]
    public string $subjectKey = '';

    public string $status = 'open';

    public array $notes = [];

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
