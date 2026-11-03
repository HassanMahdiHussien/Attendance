<x-filament-panels::page>
    <div class="space-y-5">
        <p class="text-sm text-gray-600">{{ __('messages.clear_screens.approver_context') }}</p>
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.follow_up_ui.unmapped_approvers') }}</h2>
            @if ($this->canManage()) <label>{{ __('messages.follow_up_ui.employee_search') }} <input wire:model.live.debounce.300ms="search" class="rounded-xl border p-2"></label> @endif
            <x-ui.table><thead><tr><th>{{ __('messages.clear_screens.approver_name') }}</th><th>{{ __('messages.follow_up_ui.suggestion') }}</th><th>{{ __('messages.follow_up_ui.confirm_mapping') }}</th></tr></thead><tbody>
            @forelse ($this->unmapped() as $entry)
                <tr><x-ui.td :label="__('messages.clear_screens.approver_name')"><strong>{{ $entry['text'] }}</strong> · <bdi dir="ltr">{{ $entry['count'] }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.follow_up_ui.suggestion')">
                    @foreach ($entry['suggestions'] as $suggestion)<p>{{ __('messages.follow_up_ui.suggestion') }}: {{ $suggestion->name }} · <bdi dir="ltr">{{ $suggestion->employee_code }}</bdi></p>@endforeach
                    </x-ui.td><x-ui.td :label="__('messages.follow_up_ui.confirm_mapping')">
                    @if ($this->canManage())
                        <select wire:model="employeeIds.{{ $entry['key'] }}" class="rounded-xl border p-2"><option value="">{{ __('messages.follow_up_ui.choose_employee') }}</option>
                            @foreach ($entry['suggestions'] as $suggestion)<option value="{{ $suggestion->id }}">{{ $suggestion->name }} · {{ $suggestion->employee_code }}</option>@endforeach
                            @foreach ($this->employees() as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="confirm(@js($entry['text']))" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.confirm_mapping') }}</button>
                        @error('employeeIds.'.$entry['key']) <p class="text-red-600">{{ $message }}</p> @enderror
                    @endif
                    </x-ui.td></tr>
            @empty <tr><td colspan="3"><x-ui.empty :title="__('messages.follow_up_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        </section>
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.follow_up_ui.confirmed_mappings') }}</h2>
            <x-ui.table><thead><tr><th>{{ __('messages.clear_screens.approver_name') }}</th><th>{{ __('messages.clear_screens.employee') }}</th><th>{{ __('messages.follow_up_ui.confirm_mapping') }}</th></tr></thead><tbody>
            @forelse ($this->mappings() as $mapping)
                <tr><x-ui.td :label="__('messages.clear_screens.approver_name')">{{ $mapping->approver_text }}</x-ui.td><x-ui.td :label="__('messages.clear_screens.employee')">{{ $mapping->employee?->name ?? '—' }} <bdi dir="ltr">{{ $mapping->employee?->employee_code ?? '—' }}</bdi></x-ui.td><x-ui.td :label="__('messages.follow_up_ui.confirm_mapping')">
                    @if ($this->canManage())
                        <select wire:model="employeeIds.{{ sha1($mapping->approver_key) }}" class="rounded-xl border p-2"><option value="">{{ __('messages.follow_up_ui.choose_employee') }}</option><option value="{{ $mapping->employee_id }}">{{ $mapping->employee?->name }} · {{ $mapping->employee?->employee_code }}</option>
                            @foreach ($this->employees() as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="confirm(@js($mapping->approver_text))" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.confirm_mapping') }}</button>
                    @endif
                </x-ui.td></tr>
            @empty <tr><td colspan="3"><x-ui.empty :title="__('messages.follow_up_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        </section>
    </div>
</x-filament-panels::page>
