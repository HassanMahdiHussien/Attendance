<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.credits') }}</p>
    <div class="space-y-5">
        <x-ui.card :title="__('messages.leave_ui.credits_title')">
            <div class="relative text-sm">
                <label for="credit-employee-search">{{ __('messages.leave_ui.employee') }}</label>
                <div class="flex gap-2"><input id="credit-employee-search" type="search" wire:model.live.debounce.250ms="employeeSearch" role="combobox" aria-autocomplete="list" aria-controls="credit-employee-results" aria-expanded="{{ $employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control rounded-xl border border-gray-200 p-2" />
                    @if ($employeeSearch !== '') <button type="button" wire:click="clearEmployee" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_employee') }}">{{ __('messages.reporting_ui.all') }}</button> @endif</div>
                @if ($employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2)
                    <div id="credit-employee-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                        @forelse ($this->searchEmployees() as $employee)
                            <button type="button" role="option" aria-selected="false" wire:click="chooseEmployee({{ $employee->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light focus:bg-primary-light"><strong class="block">{{ $employee->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch_name }}</span></button>
                        @empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse
                    </div>
                @endif
            </div>
            @if ($this->employee()) <p class="mt-2"><bdi dir="ltr">{{ $this->employee()->employee_code }}</bdi></p> @endif
        </x-ui.card>
        @if ($this->employee())
            @php $credits = $this->credits(); $deductions = $this->deductions(); $reversals = $this->reversals(); @endphp
            <x-ui.card :title="__('messages.leave_ui.credit_balance')">
                <x-ui.table>
                    <thead><tr>@foreach (['form_number', 'minutes', 'used', 'available', 'expires_on'] as $column)<th scope="col">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse ($credits as $credit)
                        <tr wire:key="leave-credit-{{ $credit->id }}">
                            <x-ui.td :label="__('messages.leave_ui.form_number')"><bdi dir="ltr">{{ $credit->sourceDay->request->external_id }}</bdi><span class="block text-xs">{{ $credit->sourceDay->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</span></x-ui.td>
                            <x-ui.td :label="__('messages.leave_ui.minutes')"><bdi dir="auto">{{ $this->minutes($credit->minutes) }}</bdi></x-ui.td>
                            <x-ui.td :label="__('messages.leave_ui.used')"><bdi dir="auto">{{ $this->minutes($this->creditUsed($credit->id)) }}</bdi></x-ui.td>
                            <x-ui.td :label="__('messages.leave_ui.available')"><bdi dir="auto">{{ $this->minutes($credit->minutes - $this->creditUsed($credit->id)) }}</bdi></x-ui.td>
                            <x-ui.td :label="__('messages.leave_ui.expires_on')">{{ $credit->expires_on?->locale(app()->getLocale())->translatedFormat('l j F Y') ?? '—' }}</x-ui.td>
                        </tr>
                    @empty <tr><td colspan="5" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
                    </tbody>
                </x-ui.table>
                {{ $credits->links() }}
            </x-ui.card>
            <x-ui.card :title="__('messages.leave_ui.deductions')">
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-ui.table><thead><tr><th scope="col">{{ __('messages.leave_ui.deductions') }}</th><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.leave_ui.actions') }}</th></tr></thead><tbody>
                @forelse ($deductions as $deduction)
                    <tr wire:key="leave-deduction-{{ $deduction->id }}"><x-ui.td :label="__('messages.leave_ui.deductions')">{{ app(\App\Services\Leave\LeaveExplanation::class)->render($deduction->explanation) }}</x-ui.td><x-ui.td :label="__('messages.office_ui.date')">{{ $deduction->targetDay?->work_date?->locale(app()->getLocale())->translatedFormat('l j F Y') ?? '—' }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.actions')"><button class="ui-link" wire:click="reverseDeduction({{ $deduction->id }})">{{ __('messages.leave_ui.reverse_deduction') }}</button></x-ui.td></tr>
                @empty @if($reversals->count() === 0)<tr><td colspan="3" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr>@endif @endforelse
                @foreach ($reversals as $decision)
                    <tr wire:key="leave-reversal-{{ $decision->id }}"><x-ui.td :label="__('messages.leave_ui.deductions')">{{ __('messages.leave_ui.decision_kinds.'.$decision->decision) }} · {{ $decision->reason }}</x-ui.td><x-ui.td :label="__('messages.office_ui.date')">{{ $decision->work_date?->locale(app()->getLocale())->translatedFormat('l j F Y') ?? '—' }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.actions')">@if ($decision->decision === 'reverse_deduction' && ! $this->isWithdrawn($decision))<button class="ui-link" wire:click="withdraw({{ $decision->id }})">{{ __('messages.leave_ui.withdraw') }}</button>@else — @endif</x-ui.td></tr>
                @endforeach
                </tbody></x-ui.table>
                {{ $deductions->links() }} {{ $reversals->links() }}
            </x-ui.card>
        @endif
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.leave_ui.credit_settings') }}</summary>
        <x-ui.card :title="__('messages.leave_ui.credit_settings')">
            <form wire:submit="saveExpiry" class="flex flex-wrap items-end gap-3"><label>{{ __('messages.leave_ui.expiry_days') }} <input type="number" min="0" max="65535" wire:model="expiryDays" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                <span>{{ __('messages.leave_ui.blank_never') }}</span>
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="settingReason" class="rounded-xl border border-gray-200 p-2"></label>
                <x-filament::button type="submit">{{ __('messages.leave_ui.save') }}</x-filament::button></form>
            @error('expiryDays') <p class="text-red-600">{{ $message }}</p> @enderror @error('settingReason') <p class="text-red-600">{{ $message }}</p> @enderror
        </x-ui.card>
        <x-ui.card :title="__('messages.leave_ui.leave_calendar_settings')">
            <form wire:submit="saveLeaveCalendar" class="space-y-3">
                <p>{{ __('messages.leave_ui.leave_calendar_rest_days') }}</p>
                <div class="flex flex-wrap gap-3">@foreach (__('messages.office_ui.weekdays') as $number => $label)
                    <label><input type="checkbox" value="{{ $number }}" wire:model="leaveCalendarRestDays"> {{ $label }}</label>
                @endforeach</div>
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="calendarReason" class="rounded-xl border border-gray-200 p-2"></label>
                <x-filament::button type="submit">{{ __('messages.leave_ui.save') }}</x-filament::button>
            </form>
            @error('leaveCalendarRestDays') <p class="text-red-600">{{ $message }}</p> @enderror
            @error('calendarReason') <p class="text-red-600">{{ $message }}</p> @enderror
        </x-ui.card></details>
    </div>
</x-filament-panels::page>
