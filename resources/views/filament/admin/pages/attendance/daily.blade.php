<x-filament-panels::page>
    @php $holidayNames = $this->holidayNames(); @endphp
    <div class="space-y-5">
        <x-ui.card>
            <div class="flex flex-wrap gap-4">
                <label>{{ __('messages.office_ui.date') }} <input type="date" wire:model.live="date" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                <label>{{ __('messages.office_ui.branch') }} <select wire:model.live="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label>{{ __('messages.office_ui.status') }} <select wire:model.live="status" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach (\App\Filament\Admin\Pages\Attendance\DailyAttendance::statuses() as $state)<option value="{{ $state }}">{{ __('messages.office_ui.statuses.'.$state) }}</option>@endforeach</select></label>
            </div>
        </x-ui.card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($this->summary() as $state => $count)
                <x-ui.card><span class="text-sm text-gray-600">{{ __('messages.office_ui.statuses.'.$state) }}</span><strong class="mt-1 block font-mono text-2xl" dir="ltr">{{ $count }}</strong></x-ui.card>
            @endforeach
        </div>
        <x-ui.card padding="none">
            <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr>
                @foreach (['employee', 'branch', 'status', 'shift', 'first_in', 'last_out', 'elapsed', 'presence', 'late', 'early', 'short', 'balance', 'reviewed'] as $column)<th class="whitespace-nowrap p-3">{{ __('messages.office_ui.'.$column) }}</th>@endforeach
                <th class="p-3">{{ __('messages.holiday_ui.holiday_names') }}</th>
            </tr></thead><tbody>
                @forelse ($this->days() as $day)
                    <tr class="border-t" wire:key="office-day-{{ $day->id }}">
                        <td class="p-3"><a class="font-semibold text-primary underline" href="{{ $this->detailUrl($day) }}">{{ $day->employee->name }}</a><br><bdi class="font-mono text-xs" dir="ltr">{{ $day->employee->employee_code }}</bdi></td>
                        <td class="p-3">{{ $day->branch?->name ?? '—' }}</td>
                        <td class="p-3"><x-ui.badge :tone="match ($day->status) { 'present' => 'green', 'absent', 'device_outage' => 'red', 'missing_punch', 'pending' => 'amber', default => 'gray' }">{{ __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge></td>
                        <td class="p-3"><bdi class="font-mono" dir="ltr">{{ \App\Services\Attendance\ShiftDisplay::hours($day->shift_start, $day->policy?->shift_minutes) ?? '—' }}</bdi>@if ($day->shift_source)<span class="block text-xs">{{ __('messages.office_ui.shift_sources.'.$day->shift_source) }}</span>@endif</td>
                        <td class="p-3 font-mono" dir="ltr">{{ $day->first_in?->format('H:i') ?? '—' }}</td><td class="p-3 font-mono" dir="ltr">{{ $day->last_out?->format('H:i') ?? '—' }}</td>
                        @foreach (['elapsed_minutes', 'presence_minutes', 'late_minutes', 'early_minutes', 'short_minutes', 'balance_minutes'] as $field)<td class="p-3 font-mono" dir="ltr">{{ $this->minutes($day->{$field}) }}</td>@endforeach
                        <td class="p-3">{{ $day->reviewed ? __('messages.office_ui.yes') : '—' }}</td>
                        <td class="p-3">@foreach ($day->holiday_ids ?? [] as $holidayId)<a class="ui-link block" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['holiday' => $holidayId]) }}">{{ $holidayNames[$holidayId] ?? '' }}</a>@endforeach</td>
                    </tr>
                @empty <tr><td colspan="14" class="p-5">{{ __('messages.office_ui.no_days') }}</td></tr> @endforelse
            </tbody></table></div>
            <div class="p-3">{{ $this->days()->links() }}</div>
        </x-ui.card>
    </div>
</x-filament-panels::page>
