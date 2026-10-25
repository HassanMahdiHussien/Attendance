<x-filament-panels::page>
    <div class="space-y-5">
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.follow_up_ui.unmapped_approvers') }}</h2>
            @if ($this->canManage()) <label>{{ __('messages.follow_up_ui.employee_search') }} <input wire:model.live.debounce.300ms="search" class="rounded-xl border p-2"></label> @endif
            @forelse ($this->unmapped() as $entry)
                <div class="border-b py-3"><strong>{{ $entry['text'] }}</strong> · <bdi dir="ltr">{{ $entry['count'] }}</bdi>
                    @foreach ($entry['suggestions'] as $suggestion)<p>{{ __('messages.follow_up_ui.suggestion') }}: {{ $suggestion->name }} · <bdi dir="ltr">{{ $suggestion->employee_code }}</bdi></p>@endforeach
                    @if ($this->canManage())
                        <select wire:model="employeeIds.{{ $entry['key'] }}" class="rounded-xl border p-2"><option value="">{{ __('messages.follow_up_ui.choose_employee') }}</option>
                            @foreach ($entry['suggestions'] as $suggestion)<option value="{{ $suggestion->id }}">{{ $suggestion->name }} · {{ $suggestion->employee_code }}</option>@endforeach
                            @foreach ($this->employees() as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="confirm(@js($entry['text']))" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.confirm_mapping') }}</button>
                        @error('employeeIds.'.$entry['key']) <p class="text-red-600">{{ $message }}</p> @enderror
                    @endif
                </div>
            @empty <p>{{ __('messages.follow_up_ui.none') }}</p> @endforelse
        </section>
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.follow_up_ui.confirmed_mappings') }}</h2>
            @foreach ($this->mappings() as $mapping)
                <div class="border-b py-2">{{ $mapping->approver_text }} → {{ $mapping->employee?->name }} <bdi dir="ltr">{{ $mapping->employee?->employee_code }}</bdi>
                    @if ($this->canManage())
                        <select wire:model="employeeIds.{{ sha1($mapping->approver_key) }}" class="rounded-xl border p-2"><option value="">{{ __('messages.follow_up_ui.choose_employee') }}</option><option value="{{ $mapping->employee_id }}">{{ $mapping->employee?->name }} · {{ $mapping->employee?->employee_code }}</option>
                            @foreach ($this->employees() as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="confirm(@js($mapping->approver_text))" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.confirm_mapping') }}</button>
                    @endif
                </div>
            @endforeach
        </section>
    </div>
</x-filament-panels::page>
