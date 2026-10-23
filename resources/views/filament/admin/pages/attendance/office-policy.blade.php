<x-filament-panels::page>
    <div class="space-y-5">
        @php $current = $this->current(); @endphp
        <x-ui.card :title="__('messages.office_ui.current_policy')">
            @if ($current)
                <p>{{ __('messages.office_ui.version') }} <bdi dir="ltr">{{ $current->version }}</bdi> · {{ __('messages.office_ui.effective_from') }} <bdi dir="ltr">{{ $current->effective_from->toDateString() }}</bdi></p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['office_start', 'flex_minutes', 'workday_minutes', 'min_presence_minutes', 'close_grace_minutes'] as $field)
                        <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $current->{$field} }}</strong></div>
                    @endforeach
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.exits_count_toward_balance') }}</span><strong class="block">{{ $current->exits_count_toward_balance ? __('messages.office_ui.yes') : __('messages.office_ui.no') }}</strong></div>
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.office_rest_days') }}</span><strong class="block font-mono" dir="ltr">{{ implode(', ', $current->office_rest_days ?? []) }}</strong></div>
                </div>
            @else <p>{{ __('messages.office_ui.no_policy') }}</p> @endif
        </x-ui.card>
        <x-ui.card :title="__('messages.office_ui.add_version')">
            <form wire:submit="addVersion" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label>{{ __('messages.office_ui.effective_from') }} <input type="date" wire:model="effectiveFrom" dir="ltr" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.office_ui.policy_fields.office_start') }} <input type="time" wire:model="officeStart" dir="ltr" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.office_ui.policy_fields.flex_minutes') }} <input type="number" wire:model="flexMinutes" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.workday_minutes') }} <input type="number" wire:model="workdayMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.min_presence_minutes') }} <input type="number" wire:model="minPresenceMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.close_grace_minutes') }} <input type="number" wire:model="closeGraceMinutes" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="exitsCountTowardBalance">{{ __('messages.office_ui.policy_fields.exits_count_toward_balance') }}</label>
                </div>
                <fieldset><legend>{{ __('messages.office_ui.policy_fields.office_rest_days') }}</legend><div class="flex flex-wrap gap-3">@foreach (range(1, 7) as $weekday)<label><input type="checkbox" wire:model="officeRestDays" value="{{ $weekday }}"> {{ __('messages.office_ui.weekdays.'.$weekday) }}</label>@endforeach</div></fieldset>
                <label class="block">{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reason" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                @foreach (['effectiveFrom', 'officeStart', 'flexMinutes', 'workdayMinutes', 'minPresenceMinutes', 'closeGraceMinutes', 'officeRestDays', 'reason'] as $field) @error($field) <p class="text-red-600">{{ $message }}</p> @enderror @endforeach
                <x-filament::button type="submit">{{ __('messages.office_ui.add_version') }}</x-filament::button>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('messages.office_ui.history')" padding="none">
            <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>@foreach (['version', 'effective_from', 'by', 'reason'] as $column)<th class="p-3">{{ __('messages.office_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
                @foreach ($this->history() as $policy)
                    <tr class="border-t" wire:key="office-policy-{{ $policy->id }}"><td class="p-3 font-mono" dir="ltr">{{ $policy->version }}</td><td class="p-3 font-mono" dir="ltr">{{ $policy->effective_from->toDateString() }}</td><td class="p-3">{{ $policy->creator?->name ?? __('messages.office_ui.system') }}</td><td class="p-3">{{ str_starts_with((string) $policy->reason, 'office_presence_540_from_policy:') ? __('messages.office_ui.office_presence_replacement_reason') : ($policy->reason ?? '—') }}</td></tr>
                @endforeach
            </tbody></table></div><div class="p-3">{{ $this->history()->links() }}</div>
        </x-ui.card>
    </div>
</x-filament-panels::page>
