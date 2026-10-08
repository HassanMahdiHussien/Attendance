<div class="space-y-5">
    <x-ui.page-header :title="__('Cases for this metric')" :subtitle="__('Case count: :count', ['count' => \App\Support\Format\Ui::number($cases->total())])" :back="auth()->user()->primary_role === \App\Enums\UserRole::HrPartnerStaff ? '/home' : '/dashboard'" />
    <div class="space-y-3">
        @forelse($cases as $case)
            @php
                $partner = auth()->user()->primary_role === \App\Enums\UserRole::HrPartnerStaff;
                $name = $partner ? $case->employee_name : $case->employee?->name;
                $position = $partner ? $case->employee_position : $case->employee?->position;
                $department = $partner ? $case->employee_department : $case->employee?->department;
                $archived = $case->status === \App\Enums\CaseStatus::ArchivedFinal;
                $step = \App\Support\Format\StageIndicator::step($case->status);
            @endphp
            <x-ui.card padding="none" class="{{ $archived ? 'opacity-60' : '' }}">
                <a href="/cases/{{ $case->id }}" class="flex min-w-0 items-center gap-4 rounded-2xl p-4 hover:bg-primary-light focus-visible:outline-2 focus-visible:outline-primary">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-primary-light font-bold text-primary">{{ \App\Support\Format\Initials::for($name ?? '') }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="font-bold text-ink">{{ $name }}</span>
                        <bdi class="ms-2 text-xs text-gray-500">{{ \App\Support\Format\Ui::number($case->case_number) }}</bdi>
                        <span class="mt-1 block text-xs text-gray-500">{{ $position }} — {{ $department }} · {{ $case->violation_type }} · {{ __('Incident') }} {{ \App\Support\Format\Ui::date($case->violation_time) }}</span>
                        @if($case->defense_letter_issued_at)<span class="mt-1 block text-xs text-gray-500">{{ __('Defense letter') }} {{ \App\Support\Format\Ui::date($case->defense_letter_issued_at) }}</span>@endif
                        @if($case->decision_issued_at)<span class="mt-1 block text-xs text-gray-500">{{ __('Decision') }} {{ \App\Support\Format\Ui::date($case->decision_issued_at) }}</span>@elseif($case->closed_at)<span class="mt-1 block text-xs text-gray-500">{{ __('Closure') }} {{ \App\Support\Format\Ui::date($case->closed_at) }}</span>@endif
                    </span>
                    <span class="hidden gap-1.5 sm:flex" aria-label="{{ \App\Support\Format\Ui::stage($case->status) }}">@for($i=1;$i<=5;$i++)<span class="h-2 w-2 rounded-full {{ $i <= $step ? ($archived ? 'bg-green' : 'bg-primary') : 'bg-gray-200' }}"></span>@endfor</span>
                    <x-ui.badge>{{ \App\Support\Format\Ui::stage($case->status) }}</x-ui.badge>
                </a>
            </x-ui.card>
            @if($case->employee_id && app(\App\Policies\EmployeeProfilePolicy::class)->companyAllowed(auth()->user(), (int) $case->company_id))
                <a href="{{ route('employees.show', $case->employee_id) }}" class="ui-link ms-4 text-sm">{{ __('Employee page') }}</a>
            @endif
        @empty
            <x-ui.card><x-ui.empty :title="__('No matching cases.')" /></x-ui.card>
        @endforelse
    </div>
    <div>{{ $cases->links() }}</div>
</div>
