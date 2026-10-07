<?php

namespace App\Livewire\Dashboard;

use App\Enums\UserRole;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class PartnerCases extends Component
{
    use WithPagination;

    public int $company;

    public function mount(int $company): void
    {
        $this->company = $company;
        $this->authorizeAccess();
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless($user->primary_role === UserRole::HrPartnerStaff
            && DB::table('company_hr_partner')->where('hr_partner_id', $user->hr_partner_id)
                ->where('company_id', $this->company)->where('full_case_access', true)->exists(), 403);
    }

    public function render(): View
    {
        $this->authorizeAccess();

        return view('livewire.dashboard.case-drilldown', ['cases' => CaseVisibility::for(auth()->user())
            ->where('cases.company_id', $this->company)->where('cases.status', '!=', 'draft')
            ->selectRaw('cases.*,
                (SELECT e.name FROM employees e WHERE e.id = cases.employee_id AND e.company_id = cases.company_id) as employee_name,
                (SELECT e.position FROM employees e WHERE e.id = cases.employee_id AND e.company_id = cases.company_id) as employee_position,
                (SELECT e.department FROM employees e WHERE e.id = cases.employee_id AND e.company_id = cases.company_id) as employee_department')
            ->orderByDesc('cases.created_at')->paginate(25)])->layout('layouts.workspace');
    }
}
