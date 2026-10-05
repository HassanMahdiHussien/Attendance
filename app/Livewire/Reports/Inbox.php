<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Services\Cases\CaseInsights;
use App\Services\Reports\ReportService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Inbox extends Component
{
    public array $reasons = [];

    public array $existingCaseIds = [];

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
    }

    public function dismiss(int $id, ReportService $reports): void
    {
        $this->mount();
        $this->validate(["reasons.{$id}" => ['required', 'string', 'max:2000']]);
        $reports->dismiss(ViolationReportVisibility::inbox(auth()->user())->findOrFail($id), $this->reasons[$id], auth()->user());
        unset($this->reasons[$id]);
    }

    public function convert(int $id, ReportService $reports): void
    {
        $this->mount();
        $report = ViolationReportVisibility::inbox(auth()->user())->findOrFail($id);
        $selected = $this->existingCaseIds[$id] ?? '';
        $existing = $selected !== '' ? CaseVisibility::for(auth()->user())->findOrFail($selected) : null;
        if ($existing === null) {
            $this->redirect('/cases/new?report='.$report->id, navigate: true);

            return;
        }
        $case = $reports->convert($report, auth()->user(), $existing);
        $this->redirect('/cases/'.$case->id, navigate: true);
    }

    public function render(): View
    {
        $this->mount();

        return view('livewire.reports.inbox', [
            'reports' => ViolationReportVisibility::inbox(auth()->user())->with(['reporter', 'attachments'])->latest()->get(),
            'insights' => app(CaseInsights::class),
        ])->layout('layouts.workspace');
    }
}
