<x-filament-panels::page>
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
            <x-ui.card :title="__('messages.leave_ui.credit_balance')">
                @forelse ($this->credits() as $credit)
                    <p class="border-b py-2" wire:key="leave-credit-{{ $credit->id }}"><bdi dir="ltr">{{ $credit->sourceDay->request->external_id }} · {{ $credit->sourceDay->work_date->toDateString() }}</bdi>
                        · {{ __('messages.leave_ui.minutes') }} <bdi dir="ltr">{{ $this->minutes($credit->minutes) }}</bdi>
                        · {{ __('messages.leave_ui.used') }} <bdi dir="ltr">{{ $this->minutes($this->creditUsed($credit->id)) }}</bdi>
                        · {{ __('messages.leave_ui.available') }} <bdi dir="ltr">{{ $this->minutes($credit->minutes - $this->creditUsed($credit->id)) }}</bdi>
                        · {{ __('messages.leave_ui.expires_on') }} <bdi dir="ltr">{{ $credit->expires_on?->toDateString() ?? '—' }}</bdi></p>
                @empty <p>—</p> @endforelse
            </x-ui.card>
            <x-ui.card :title="__('messages.leave_ui.deductions')">
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                @forelse ($this->deductions() as $deduction)
                    <p class="border-b py-2" wire:key="leave-deduction-{{ $deduction->id }}">{{ app(\App\Services\Leave\LeaveExplanation::class)->render($deduction->explanation) }}
                        <button class="ms-3 underline" wire:click="reverseDeduction({{ $deduction->id }})">{{ __('messages.leave_ui.reverse_deduction') }}</button></p>
                @empty <p>—</p> @endforelse
                @foreach ($this->reversals() as $decision)
                    <p class="border-b py-2" wire:key="leave-reversal-{{ $decision->id }}">{{ __('messages.leave_ui.decision_kinds.'.$decision->decision) }} · <bdi dir="ltr">{{ $decision->work_date?->toDateString() }}</bdi> · {{ $decision->reason }}
                        @if ($decision->decision === 'reverse_deduction' && ! $this->isWithdrawn($decision))<button class="ms-3 underline" wire:click="withdraw({{ $decision->id }})">{{ __('messages.leave_ui.withdraw') }}</button>@endif</p>
                @endforeach
            </x-ui.card>
        @endif
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
        </x-ui.card>
    </div>
</x-filament-panels::page>
