<x-filament-panels::page>
    @php $holidayNames = $this->holidayNames(); $overtimeRows = $this->overtimeRows(); $entitlementRows = $this->entitlementRows(); $presentations = $this->dayPresentations(); @endphp
    <div class="space-y-5">
        <p class="text-sm text-gray-600">{{ __('messages.office_ui.date') }}: {{ preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->translatedFormat('l j F Y') : $date }}</p>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($this->summary() as $state => $count)
                <x-ui.card><span class="text-sm text-gray-600">{{ __('messages.office_ui.statuses.'.$state) }}</span><strong class="mt-1 block font-mono text-2xl"><bdi dir="ltr">{{ $count }}</bdi></strong></x-ui.card>
            @endforeach
        </div>
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4" @if($branchId !== '' || $status !== '') open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_filters') }}</summary>
        <x-ui.card>
            <div class="flex flex-wrap gap-4">
                <label>{{ __('messages.office_ui.date') }} <input type="date" wire:model.live="date" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                <label>{{ __('messages.office_ui.branch') }} <select wire:model.live="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label>{{ __('messages.office_ui.status') }} <select wire:model.live="status" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach (\App\Filament\Admin\Pages\Attendance\DailyAttendance::statuses() as $state)<option value="{{ $state }}">{{ __('messages.office_ui.statuses.'.$state) }}</option>@endforeach</select></label>
            </div>
        </x-ui.card></details>
        <x-ui.card padding="none">
            <x-ui.table>
                <thead><tr>
                    @foreach (['employee', 'status', 'first_in', 'last_out', 'presence', 'balance'] as $column)<th scope="col">{{ __('messages.office_ui.'.$column) }}</th>@endforeach
                </tr></thead>
                <tbody x-data="{ open: null }">
                @forelse ($this->days() as $day)
                    @php $presentation = $presentations[$day->id] ?? null; @endphp
                    <tr wire:key="office-day-{{ $day->id }}">
                        <x-ui.td :label="__('messages.office_ui.employee')"><button type="button" class="text-start font-semibold text-primary" x-on:click="open = open === {{ $day->id }} ? null : {{ $day->id }}" x-bind:aria-expanded="(open === {{ $day->id }}).toString()" aria-controls="office-day-detail-{{ $day->id }}">{{ $day->employee->name }}</button><span class="block text-xs"><bdi dir="ltr">{{ $day->employee->employee_code }}</bdi> · {{ $day->branch?->name ?? '—' }}</span></x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.status')"><x-ui.badge :tone="$presentation['tone'] ?? 'gray'">{{ $presentation['label'] ?? __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge></x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.first_in')"><bdi dir="ltr" class="font-mono">{{ $day->first_in?->format('H:i') ?? '—' }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.last_out')"><bdi dir="ltr" class="font-mono">{{ $day->last_out?->format('H:i') ?? '—' }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.presence')"><bdi dir="auto">{{ $presentation['worked'] ?? $this->minutes($day->presence_minutes) }}</bdi>@if ($presentation['out'] ?? null)<span class="block text-xs text-gray-500">{{ __('messages.office_ui.out_time') }} <bdi dir="auto">{{ $presentation['out']['total'] }}</bdi>@if ($presentation['out']['over_rest']) · <bdi dir="auto">{{ $presentation['out']['over_rest'] }}</bdi> {{ __('messages.office_ui.over_rest') }}@endif</span>@endif</x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.balance')"><bdi dir="auto">{{ $day->balance_minutes ? $this->minutes($day->balance_minutes) : '—' }}</bdi></x-ui.td>
                    </tr>
                    <tr id="office-day-detail-{{ $day->id }}" x-show="open === {{ $day->id }}" x-cloak wire:key="office-day-extra-{{ $day->id }}"><td colspan="6" class="p-4 text-sm"><div class="space-y-2">
                        <a class="ui-link" href="{{ $this->detailUrl($day) }}">{{ __('messages.reporting_ui.day_detail') }}</a>
                        <p>{{ implode(' · ', $presentation['problems'] ?? []) ?: '—' }}</p>
                        @foreach ($presentation['out']['periods'] ?? [] as $period)
                            <p>{{ __('messages.office_ui.out_time') }} <bdi class="font-mono" dir="ltr">{{ $period['from'] }}</bdi> {{ __('messages.office_ui.to') }} <bdi class="font-mono" dir="ltr">{{ $period['to'] }}</bdi> (<bdi dir="auto">{{ $period['duration'] }}</bdi>)</p>
                        @endforeach
                        <p>{{ __('messages.office_ui.shift') }}: <bdi dir="ltr" class="font-mono">{{ \App\Services\Attendance\ShiftDisplay::hours($day->shift_start, $day->policy?->shift_minutes) ?? '—' }}</bdi> @if($day->shift_source){{ __('messages.office_ui.shift_sources.'.$day->shift_source) }}@endif</p>
                        <p>{{ __('messages.office_ui.elapsed') }}: <bdi dir="auto">{{ $this->minutes($day->elapsed_minutes) }}</bdi> · {{ __('messages.office_ui.presence') }}: <bdi dir="auto">{{ $this->minutes($day->presence_minutes) }}</bdi> · {{ __('messages.office_ui.late') }}: <bdi dir="auto">{{ $this->minutes($day->late_minutes) }}</bdi> · {{ __('messages.office_ui.early') }}: <bdi dir="auto">{{ $this->minutes($day->early_minutes) }}</bdi> · {{ __('messages.office_ui.short') }}: <bdi dir="auto">{{ $this->minutes($day->short_minutes) }}</bdi></p>
                        <p>{{ __('messages.office_ui.reviewed') }}: {{ $day->reviewed ? __('messages.office_ui.yes') : '—' }}</p>
                        <p>{{ __('messages.overtime_ui.overtime') }}: <bdi dir="auto">{{ $this->minutes($overtimeRows[$day->id]['overtime_minutes'] ?? null) }}</bdi> @if($overtimeRows[$day->id]['overtime_basis'] ?? null){{ __('messages.overtime_ui.basis.'.$overtimeRows[$day->id]['overtime_basis']) }}@endif · {{ __('messages.overtime_ui.early_marker') }}: <bdi dir="auto">{{ $this->minutes($overtimeRows[$day->id]['early_arrival_minutes'] ?? null) }}</bdi></p>
                        @if(isset($entitlementRows[$day->id]))<p><a class="ui-link" href="{{ $this->detailUrl($day) }}">{{ __('messages.overtime_ui.entitlement') }}: {{ number_format($entitlementRows[$day->id]['earned'], 1) }}</a></p>@endif
                        @foreach ($day->holiday_ids ?? [] as $holidayId)<a class="ui-link block" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['holiday' => $holidayId]) }}">{{ $holidayNames[$holidayId] ?? '' }}</a>@endforeach
                    </div></td></tr>
                @empty <tr><td colspan="6" class="p-5">{{ __('messages.office_ui.no_days') }}</td></tr> @endforelse
                </tbody>
            </x-ui.table>
            <div class="p-3">{{ $this->days()->links() }}</div>
        </x-ui.card>
    </div>
</x-filament-panels::page>
