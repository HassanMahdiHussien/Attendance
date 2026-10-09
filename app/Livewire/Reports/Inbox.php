<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Models\ViolationType;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseViolationTypes;
use App\Services\EmailIntake\EmailDraftService;
use App\Services\Reports\ReportService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Inbox extends Component
{
    public array $reasons = [];

    public array $existingCaseIds = [];

    public array $emailReasons = [];

    public array $emailCaseIds = [];

    public array $mapViolationTypeIds = [];

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

    public function mapViolationType(int $id, CaseViolationTypes $types): void
    {
        $this->mount();
        $this->validate(["mapViolationTypeIds.{$id}" => ['required', 'integer']]);
        $report = ViolationReportVisibility::inbox(auth()->user())->findOrFail($id);
        $types->mapReport($report, (int) $this->mapViolationTypeIds[$id], auth()->user());
        unset($this->mapViolationTypeIds[$id]);
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

        $reports = ViolationReportVisibility::inbox(auth()->user())->with(['reporter', 'attachments', 'intakeQuestions'])->latest()->get();
        $emails = EmailDraftVisibility::inbox(auth()->user())->with(['sender', 'attachments', 'intakeQuestions'])->latest('received_at')->get();
        $items = $reports->map(fn ($report) => ['type' => 'report', 'time' => $report->created_at, 'model' => $report])
            ->concat($emails->map(fn ($email) => ['type' => 'email', 'time' => $email->received_at, 'model' => $email]))
            ->sortByDesc('time')->values();

        return view('livewire.reports.inbox', [
            'items' => $items,
            'insights' => app(CaseInsights::class),
            'violationTypes' => ViolationType::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ])->layout('layouts.workspace');
    }

    public function dismissEmail(int $id, EmailDraftService $drafts): void
    {
        $this->mount();
        $this->validate(["emailReasons.{$id}" => ['required', 'string', 'max:2000']]);
        $drafts->dismiss(EmailDraftVisibility::inbox(auth()->user())->findOrFail($id), $this->emailReasons[$id], auth()->user());
        unset($this->emailReasons[$id]);
    }

    public function convertEmail(int $id): void
    {
        $this->mount();
        EmailDraftVisibility::inbox(auth()->user())->findOrFail($id);
        $this->redirect('/cases/new?email='.$id, navigate: true);
    }

    public function linkEmail(int $id, EmailDraftService $drafts): void
    {
        $this->mount();
        $draft = EmailDraftVisibility::inbox(auth()->user())->findOrFail($id);
        $selected = $this->emailCaseIds[$id] ?? '';
        abort_unless($selected !== '', 422);
        $case = CaseVisibility::for(auth()->user())->findOrFail($selected);
        $drafts->link($draft, $case, auth()->user());
        $this->redirect('/cases/'.$case->id, navigate: true);
    }
}
