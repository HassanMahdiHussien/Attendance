<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.leave_requests') }}</p>
    <x-ui.card>
            <div class="relative text-sm">
                <label for="leave-request-employee-search">{{ __('messages.leave_ui.employee') }}</label>
                <div class="flex gap-2"><input id="leave-request-employee-search" type="search" wire:model.live.debounce.250ms="employeeSearch" role="combobox" aria-autocomplete="list" aria-controls="leave-request-employee-results" aria-expanded="{{ $employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control rounded-xl border border-gray-200 p-2" />
                    @if ($employeeSearch !== '') <button type="button" wire:click="clearEmployee" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_employee') }}">{{ __('messages.leave_ui.all') }}</button> @endif</div>
                @if ($employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2)
                    <div id="leave-request-employee-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                        @forelse ($this->searchEmployees() as $employee)
                            <button type="button" role="option" aria-selected="false" wire:click="chooseEmployee({{ $employee->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light focus:bg-primary-light"><strong class="block">{{ $employee->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch_name }}</span></button>
                        @empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse
                    </div>
                @endif
            </div>
    </x-ui.card>
    <details wire:ignore.self class="rounded-xl border border-line bg-white p-4" @if($status !== '' || $typeId !== '' || $from !== '' || $to !== '' || $flag !== '') open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.leave_ui.filters') }}</summary>
    <x-ui.card>
        <div class="flex flex-wrap gap-3">
            <label>{{ __('messages.leave_ui.status') }} <select wire:model.live="status" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach (['approved', 'pending', 'draft', 'rejected', 'cancelled'] as $value)<option value="{{ $value }}">{{ __('messages.leave_ui.statuses.'.$value) }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.type') }} <select wire:model.live="typeId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->types() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.leave_ui.flag') }} <select wire:model.live="flag" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach (['unmapped_type', 'invalid_dates', 'duration_mismatch', 'counted_holiday', 'missing_from_report', 'nothing_to_reconcile'] as $value)<option value="{{ $value }}">{{ __('messages.leave_ui.flags.'.$value) }}</option>@endforeach</select></label>
        </div>
    </x-ui.card></details>
    <x-ui.card :title="__('messages.leave_ui.requests_title')">
        <div class="overflow-x-auto"><x-ui.table><thead><tr>@foreach (['form_number', 'employee', 'type', 'dates', 'duration', 'status', 'approver'] as $column)<th class="p-2">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @forelse ($this->requests() as $request)
                @php $displayStatus = $this->displayStatus($request); @endphp
                <tr class="border-t" wire:key="leave-request-{{ $request->id }}">
                    <x-ui.td :label="__('messages.leave_ui.form_number')"><a class="underline" href="{{ $this->detailUrl($request) }}"><bdi dir="ltr">{{ $request->external_id }}</bdi></a></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.employee')">{{ $request->employee?->name ?? __('messages.leave_ui.unknown_employee') }} <bdi dir="ltr">{{ $request->owner_code }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.type')">{{ $request->leaveType?->name ?? '—' }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.dates')">{{ $request->start_date?->toDateString() }} – {{ $request->end_date?->toDateString() }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.duration')"><bdi dir="ltr">{{ $request->duration_days ?? '—' }}</bdi> @if ($request->duration_days !== null){{ __('messages.leave_balance_ui.days') }}@endif</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.status')"><x-ui.badge :tone="match ($displayStatus) { 'approved' => 'green', 'rejected' => 'red', 'pending' => 'indigo', default => 'gray' }">{{ __('messages.leave_ui.statuses.'.$displayStatus) }}</x-ui.badge></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.approver')">{{ $displayStatus === 'pending' ? $request->approver_name : '—' }}</x-ui.td>
                </tr>
            @empty <tr><td colspan="7" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table></div>
        {{ $this->requests()->links() }}
    </x-ui.card>
</x-filament-panels::page>
