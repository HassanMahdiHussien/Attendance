<div class="space-y-5">
    <x-ui.page-header :title="__('messages.reporting_ui.employee_list')" :back="auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin ? route('dashboard.company', ['company' => $company->id]) : route('dashboard.index')" />
    <x-ui.card>
        <p>{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }} · <bdi dir="ltr">{{ $from }} → {{ $to }}</bdi></p>
        <p class="text-sm text-gray-500">{{ __('messages.reporting_ui.matching_employees') }}: <bdi dir="ltr">{{ $employees->count() }}</bdi></p>
    </x-ui.card>
    <x-ui.table>
        <thead><tr><th scope="col">{{ __('messages.reporting_ui.employee') }}</th><th scope="col">{{ __('messages.office_ui.branch') }}</th><th scope="col">{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }}</th><th scope="col">{{ __('messages.leave_balance_ui.annual_balance') }}</th></tr></thead>
        <tbody>
        @forelse ($employees as $employee)
            <tr>
                <x-ui.td :label="__('messages.reporting_ui.employee')"><a class="ui-link" href="{{ route('employees.show', $employee).($tile === 'absent_uncovered' ? '?'.http_build_query(['attendance_tile' => $tile, 'from' => $from, 'to' => $to]) : '') }}">{{ $employee->name }}</a><span class="block text-xs"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->department }}</span></x-ui.td>
                <x-ui.td :label="__('messages.office_ui.branch')">{{ $employee->branch?->name ?? '—' }}</x-ui.td>
                <x-ui.td :label="\App\Services\Attendance\Reporting\ReportTiles::label($tile)">@if(isset($overtimeRows[$employee->id]))@if($tile === 'overtime_minutes')<bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($overtimeRows[$employee->id][$tile]) }}</bdi>@else {{ $tile === 'entitled_employees' ? 1 : number_format($overtimeRows[$employee->id][$tile], 1) }} @endif @else — @endif</x-ui.td>
                <x-ui.td :label="__('messages.leave_balance_ui.annual_balance')">@if($balance = $balances->get($employee->id)){{ number_format((float) $balance->remaining_days, 2) }} {{ __('messages.leave_balance_ui.days') }} · <bdi dir="ltr">{{ $balance->balance_date->toDateString() }}</bdi>@else — @endif</x-ui.td>
            </tr>
        @empty <tr><td colspan="4" class="p-4">{{ __('messages.reporting_ui.none') }}</td></tr> @endforelse
        </tbody>
    </x-ui.table>
</div>
