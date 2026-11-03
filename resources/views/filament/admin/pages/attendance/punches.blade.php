<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.punches') }}</p>
    <div class="space-y-4">
        <section class="flex flex-wrap gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="relative text-sm">
                <label for="punch-employee-search">{{ __('messages.attendance_ui.employee') }}</label>
                <div class="flex gap-2"><input id="punch-employee-search" type="search" wire:model.live.debounce.250ms="employeeSearch" role="combobox" aria-autocomplete="list" aria-controls="punch-employee-results" aria-expanded="{{ $employeeId === null && mb_strlen(trim($employeeSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control rounded-xl border border-gray-200 p-2" />
                    @if ($employeeSearch !== '') <button type="button" wire:click="clearEmployee" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_employee') }}">{{ __('messages.reporting_ui.all') }}</button> @endif</div>
                @if ($employeeId === null && mb_strlen(trim($employeeSearch)) >= 2)
                    <div id="punch-employee-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                        @forelse ($this->searchEmployees() as $employee)
                            <button type="button" role="option" aria-selected="false" wire:click="chooseEmployee({{ $employee->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light focus:bg-primary-light"><strong class="block">{{ $employee->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch_name }}</span></button>
                        @empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse
                    </div>
                @endif
            </div>
            <details wire:ignore.self class="w-full" @if($personCode !== '' || $from !== today()->subDays(7)->toDateString() || $to !== today()->toDateString()) open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_filters') }}</summary><div class="flex flex-wrap gap-4 pt-3">
            <label>{{ __('messages.attendance_ui.person_code') }} <input type="text" wire:model.live="personCode" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.attendance_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.attendance_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            </div></details>
        </section>
        @forelse ($this->days() as $day => $data)
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" wire:key="attendance-day-{{ $day }}">
                <h2 class="font-bold" dir="ltr">{{ $day }}</h2>
                <p>{{ __('messages.attendance_ui.meal_count') }}: {{ $data['meal']->punch_count ?? 0 }}</p>
                <div class="overflow-x-auto"><x-ui.table><thead><tr><th class="p-2">{{ __('messages.attendance_ui.raw') }}</th><th class="p-2">{{ __('messages.attendance_ui.cleaned') }}</th></tr></thead><tbody>
                    @forelse ($data['transactions'] ?? [] as $row)
                        <tr class="border-t"><x-ui.td :label="__('messages.attendance_ui.raw')"><bdi dir="ltr">{{ $row['raw']->punched_at->format('H:i:s') }}</bdi> — {{ $row['raw']->punch_state }} — <bdi dir="ltr">{{ $row['raw']->serial_number }}</bdi> ({{ $row['raw']->device_name }})</x-ui.td>
                            <x-ui.td :label="__('messages.attendance_ui.cleaned')">@if ($row['clean']) <x-ui.badge :tone="match ($row['clean']->status) { 'used' => 'green', 'repeat' => 'amber', default => 'indigo' }">{{ __('messages.attendance_ui.punch_states.'.$row['clean']->status) }}</x-ui.badge> @if ($row['clean']->reason_code) — {{ __('messages.attendance_ui.reasons.'.$row['clean']->reason_code) }} @endif @if ($row['clean']->group_key) <bdi dir="ltr">{{ $row['clean']->group_key }}</bdi> @endif @else — @endif</x-ui.td></tr>
                    @empty <tr><td colspan="2" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
                </tbody></x-ui.table></div>
            </section>
        @empty <x-ui.card><x-ui.empty :title="__('messages.list_empty')" /></x-ui.card> @endforelse
    </div>
</x-filament-panels::page>
