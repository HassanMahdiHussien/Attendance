<div class="space-y-5">
    <x-ui.page-header :title="__('messages.reporting_ui.employee_list')" :back="auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin ? route('dashboard.company', ['company' => $company->id]) : route('dashboard.index')" />
    <x-ui.card>
        <p class="font-semibold text-ink">{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }} · <bdi dir="ltr" class="whitespace-nowrap">{{ $from }} → {{ $to }}</bdi></p>
        @if (\Illuminate\Support\Facades\Lang::has('messages.reporting_ui.task_help.'.$tile))<p class="mt-1 text-sm text-gray-600">{{ __('messages.reporting_ui.task_help.'.$tile) }} {{ __('messages.reporting_ui.open_employee_hint') }}</p>@endif
        <p class="mt-1 text-sm text-gray-500">{{ __('messages.reporting_ui.matching_employees') }}: <bdi dir="ltr">{{ $employees->count() }}</bdi></p>
    </x-ui.card>
    <x-ui.table>
        <thead><tr><th scope="col">{{ __('messages.reporting_ui.employee') }}</th><th scope="col">{{ __('messages.office_ui.branch') }}</th><th scope="col">{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }}</th>@if ($balances->isNotEmpty())<th scope="col">{{ __('messages.leave_balance_ui.annual_balance') }}</th>@endif</tr></thead>
        <tbody>
        @forelse ($employees as $employee)
            <tr>
                <x-ui.td :label="__('messages.reporting_ui.employee')"><a class="ui-link" href="{{ route('employees.show', $employee).'?'.http_build_query(['attendance_tile' => $tile, 'from' => $from, 'to' => $to]) }}">{{ $employee->name }}</a><span class="block text-xs"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->department }}</span></x-ui.td>
                <x-ui.td :label="__('messages.office_ui.branch')">{{ $employee->branch?->name ?? '—' }}</x-ui.td>
                <x-ui.td :label="\App\Services\Attendance\Reporting\ReportTiles::label($tile)">
                    @if (isset($overtimeRows[$employee->id]))
                        @if ($tile === 'overtime_minutes')<bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($overtimeRows[$employee->id][$tile]) }}</bdi>@else {{ $tile === 'entitled_employees' ? 1 : number_format($overtimeRows[$employee->id][$tile], 1) }} @endif
                    @elseif (($rows[$employee->id]['value'] ?? null) !== null)
                        @php $value = $rows[$employee->id]['value']; @endphp
                        <strong class="font-mono tabular-nums"><bdi dir="auto">{{ \App\Services\Attendance\Reporting\ReportTiles::isMinutes($tile) ? app(\App\Services\Attendance\Reporting\DurationText::class)->format((int) $value) : (\App\Services\Attendance\Reporting\ReportTiles::isDays($tile) ? number_format($value, 1) : \App\Support\Format\Ui::number($value)) }}</bdi></strong>
                        @php $days = collect($rows[$employee->id]['day_ids'])->map(fn ($id) => $dates->get($id))->filter()->sort()->values(); @endphp
                        @if ($days->isNotEmpty())
                            <span class="mt-1 flex flex-wrap gap-1">
                                @foreach ($days->take(8) as $date)<span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-ink">{{ $date->locale(app()->getLocale())->translatedFormat('j M') }}</span>@endforeach
                                @if ($days->count() > 8)<span class="px-1 text-xs text-gray-500">{{ __('messages.reporting_ui.more_days', ['count' => $days->count() - 8]) }}</span>@endif
                            </span>
                        @endif
                    @else — @endif
                </x-ui.td>
                @if ($balances->isNotEmpty())<x-ui.td :label="__('messages.leave_balance_ui.annual_balance')">@if($balance = $balances->get($employee->id)){{ number_format((float) $balance->remaining_days, 2) }} {{ __('messages.leave_balance_ui.days') }} · <bdi dir="ltr">{{ $balance->balance_date->toDateString() }}</bdi>@else — @endif</x-ui.td>@endif
            </tr>
        @empty <tr><td colspan="4" class="p-4">{{ __('messages.reporting_ui.none') }}</td></tr> @endforelse
        </tbody>
    </x-ui.table>
</div>
