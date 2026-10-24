<div class="space-y-5">
    <x-ui.page-header :title="__('messages.reporting_ui.employee_list')" :back="auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin ? route('dashboard.company', ['company' => $company->id]) : route('dashboard.index')" />
    <x-ui.card>
        <p>{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }} · <bdi dir="ltr">{{ $from }} → {{ $to }}</bdi></p>
        <p class="text-sm text-gray-500">{{ __('messages.reporting_ui.matching_employees') }}: <bdi dir="ltr">{{ $employees->count() }}</bdi></p>
    </x-ui.card>
    @forelse ($employees as $employee)
        <x-ui.card>
            <a class="ui-link" href="{{ route('employees.show', $employee) }}">{{ $employee->name }}</a>
            <span class="ms-2 text-sm text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch?->name }} · {{ $employee->department }}</span>
            @if ($balance = $balances->get($employee->id))
                <p class="mt-2 text-sm">{{ __('messages.leave_balance_ui.annual_balance') }}: <bdi dir="ltr">{{ number_format((float) $balance->remaining_days, 2) }}</bdi> {{ __('messages.leave_balance_ui.days') }} · <bdi dir="ltr">{{ $balance->balance_date->toDateString() }}</bdi></p>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card><p>{{ __('messages.reporting_ui.none') }}</p></x-ui.card>
    @endforelse
</div>
