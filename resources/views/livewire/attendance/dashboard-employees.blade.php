<div class="space-y-5">
    <x-ui.page-header :title="__('messages.reporting_ui.employee_list')" :back="auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin ? route('dashboard.company', ['company' => $company->id]) : route('dashboard.index')" />
    <x-ui.card>
        <p class="font-semibold text-ink">{{ \App\Services\Attendance\Reporting\ReportTiles::label($tile) }} · <bdi dir="ltr" class="whitespace-nowrap">{{ $from }} → {{ $to }}</bdi></p>
        @if (\Illuminate\Support\Facades\Lang::has('messages.reporting_ui.task_help.'.$tile))<p class="mt-1 text-sm text-gray-600">{{ __('messages.reporting_ui.task_help.'.$tile) }} {{ __('messages.reporting_ui.open_employee_hint') }}</p>
        @elseif (in_array($tile, ['late', 'early', 'remaining', ...\App\Services\Attendance\Reporting\ReportFilters::STATUSES], true))<p class="mt-1 text-sm text-gray-600">{{ __('messages.reporting_ui.task_help.day_status') }} {{ __('messages.reporting_ui.open_employee_hint') }}</p>@endif
        <p class="mt-1 text-sm text-gray-500">{{ __('messages.reporting_ui.matching_employees') }}: <bdi dir="ltr">{{ $employees->total() }}</bdi></p>
        <label class="mt-3 block text-sm">{{ __('messages.reporting_ui.employee_search') }}
            <input type="search" wire:model.live.debounce.300ms="search" class="ui-control mt-1 w-full max-w-sm" autocomplete="off">
        </label>
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
                        @if (($dayCounts[$employee->id] ?? 0) > 0)
                            <span class="mt-2 block min-w-0 flex-1 space-y-1 text-xs">
                                @foreach (array_slice($rows[$employee->id]['day_ids'], 0, 8) as $dayId)
                                    @if (($day = $days->get($dayId)) && isset($presentations[$dayId]))
                                        @php $presentation = $presentations[$dayId]; @endphp
                                        <a class="ui-link block whitespace-normal break-words rounded bg-gray-50 px-2 py-1" href="{{ \App\Filament\Admin\Pages\Attendance\DayDetail::getUrl(panel: 'admin', tenant: $company).'?'.http_build_query(['employee' => $employee->id, 'date' => $day->work_date->toDateString(), 'back_tile' => $tile, 'back_filters' => $filters, 'back_search' => $search, 'back_page' => $page]) }}">
                                            {{ $presentation['weekday'] }} <bdi dir="ltr">{{ $presentation['date'] }}</bdi>
                                            · {{ __('messages.reporting_ui.check_in') }} <bdi dir="ltr">{{ $day->first_in?->format('H:i') ?? '—' }}</bdi>
                                            · {{ __('messages.reporting_ui.check_out') }} <bdi dir="ltr">{{ $day->last_out?->format('H:i') ?? '—' }}</bdi>
                                            · {{ __('messages.reporting_ui.worked') }} <bdi dir="auto">{{ $presentation['worked'] }}</bdi>
                                            · {{ implode(' · ', $presentation['problems'] ?: [$presentation['label']]) }}
                                            @if ($tile === 'shift_unresolved' && ($candidates = \App\Services\Attendance\ShiftDisplay::candidateStarts($day->shift_evidence)) !== [])
                                                · {{ __('messages.reporting_ui.possible_shifts') }} <bdi dir="ltr">{{ implode(', ', $candidates) }}</bdi>
                                            @endif
                                        </a>
                                    @endif
                                @endforeach
                                @if ($dayCounts[$employee->id] > 8)<span class="block text-gray-500">{{ __('messages.reporting_ui.more_days', ['count' => $dayCounts[$employee->id] - 8]) }}</span>@endif
                            </span>
                        @endif
                    @else — @endif
                </x-ui.td>
                @if ($balances->isNotEmpty())<x-ui.td :label="__('messages.leave_balance_ui.annual_balance')">@if($balance = $balances->get($employee->id)){{ number_format((float) $balance->remaining_days, 2) }} {{ __('messages.leave_balance_ui.days') }} · <bdi dir="ltr">{{ $balance->balance_date->toDateString() }}</bdi>@else — @endif</x-ui.td>@endif
            </tr>
        @empty <tr><td colspan="4" class="p-4">{{ __('messages.reporting_ui.none') }}</td></tr> @endforelse
        </tbody>
    </x-ui.table>
    @if ($employees->lastPage() > 1)
        <nav class="flex items-center justify-between gap-3 text-sm" aria-label="{{ __('messages.reporting_ui.pages') }}">
            <button type="button" wire:click="previousPage" @disabled($employees->onFirstPage()) class="ui-button ui-button-secondary ui-button-small">{{ __('messages.reporting_ui.previous_page') }}</button>
            <span><bdi dir="ltr">{{ $employees->currentPage() }} / {{ $employees->lastPage() }}</bdi></span>
            <button type="button" wire:click="nextPage" @disabled(! $employees->hasMorePages()) class="ui-button ui-button-secondary ui-button-small">{{ __('messages.reporting_ui.next_page') }}</button>
        </nav>
    @endif
</div>
