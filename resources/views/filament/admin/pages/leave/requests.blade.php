<x-filament-panels::page>
    <x-ui.card :title="__('messages.leave_ui.filters')">
        <div class="flex flex-wrap gap-3">
            <label>{{ __('messages.leave_ui.status') }} <select wire:model.live="status" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach (['approved', 'pending', 'draft', 'rejected', 'cancelled'] as $value)<option value="{{ $value }}">{{ __('messages.leave_ui.statuses.'.$value) }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.type') }} <select wire:model.live="typeId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->types() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.employee') }} <select wire:model.live="employeeId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->employees() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.leave_ui.flag') }} <select wire:model.live="flag" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach (['unmapped_type', 'invalid_dates', 'duration_mismatch', 'missing_from_report', 'nothing_to_reconcile'] as $value)<option value="{{ $value }}">{{ __('messages.leave_ui.flags.'.$value) }}</option>@endforeach</select></label>
        </div>
    </x-ui.card>
    <x-ui.card :title="__('messages.leave_ui.requests_title')">
        <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>@foreach (['form_number', 'employee', 'type', 'dates', 'duration', 'status', 'approver'] as $column)<th class="p-2">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @foreach ($this->requests() as $request)
                @php $displayStatus = $this->displayStatus($request); @endphp
                <tr class="border-t" wire:key="leave-request-{{ $request->id }}">
                    <td class="p-2"><a class="underline" href="{{ $this->detailUrl($request) }}"><bdi dir="ltr">{{ $request->external_id }}</bdi></a></td>
                    <td class="p-2">{{ $request->employee?->name ?? __('messages.leave_ui.unknown_employee') }} <bdi dir="ltr">{{ $request->owner_code }}</bdi></td>
                    <td class="p-2">{{ $request->leaveType?->name ?? '—' }}</td>
                    <td class="p-2" dir="ltr">{{ $request->start_date?->toDateString() }} – {{ $request->end_date?->toDateString() }}</td>
                    <td class="p-2" dir="ltr">{{ $request->duration_days ?? '—' }}</td>
                    <td class="p-2">{{ __('messages.leave_ui.statuses.'.$displayStatus) }}</td>
                    <td class="p-2">{{ $displayStatus === 'pending' ? $request->approver_name : '—' }}</td>
                </tr>
            @endforeach
        </tbody></table></div>
        {{ $this->requests()->links() }}
    </x-ui.card>
</x-filament-panels::page>
