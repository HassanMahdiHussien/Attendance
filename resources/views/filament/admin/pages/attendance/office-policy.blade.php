<x-filament-panels::page>
    <div class="space-y-5">
        <p class="text-sm text-gray-600">{{ __('messages.clear_screens.office_policy_context') }}</p>
        @php $current = $this->current(); @endphp
        @php $waiting = $this->recalculatingMonths(); @endphp
        <div @if ($waiting > 0) wire:poll.10s @endif class="rounded-xl border p-3 text-sm {{ $waiting > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white' }}">
            {{ $waiting > 0 ? __('messages.office_ui.recalculating', ['count' => $waiting]) : __('messages.office_ui.recalculated') }}
        </div>
        <x-ui.card :title="__('messages.office_ui.current_policy')">
            @if ($current)
                <p>{{ __('messages.office_ui.version') }} <bdi dir="ltr">{{ $current->version }}</bdi> · {{ __('messages.office_ui.effective_from') }} <bdi dir="ltr">{{ $current->effective_from->toDateString() }}</bdi></p>
                <h3 class="mt-4 font-semibold">{{ __('messages.office_ui.policy_sections.office') }}</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['office_start', 'flex_minutes', 'workday_minutes', 'min_presence_minutes', 'absence_minutes', 'close_grace_minutes', 'minor_gap_tolerance_minutes'] as $field)
                        <div><span class="text-sm text-gray-600">{{ str_replace(' ('.__('messages.clear_screens.minutes_unit').')', '', __('messages.office_ui.policy_fields.'.$field)) }}</span><strong class="block {{ $field === 'office_start' ? 'font-mono' : '' }}"><bdi dir="{{ $field === 'office_start' ? 'ltr' : 'auto' }}">{{ $field === 'office_start' ? $current->{$field} : app(\App\Services\Attendance\Reporting\DurationText::class)->format($current->{$field}) }}</bdi></strong></div>
                    @endforeach
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.rest_allowance') }}</span><strong class="block"><bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format(\App\Services\Attendance\OfficePolicy::restMinutes((int) $current->workday_minutes, (int) $current->min_presence_minutes)) }}</bdi></strong></div>
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.exits_count_toward_balance') }}</span><strong class="block">{{ $current->exits_count_toward_balance ? __('messages.office_ui.yes') : __('messages.office_ui.no') }}</strong></div>
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.office_rest_days') }}</span><strong class="block">{{ implode(', ', array_map(fn ($day) => __('messages.office_ui.weekdays.'.$day), $current->office_rest_days ?? [])) }}</strong></div>
                </div>
                <h3 class="mt-4 font-semibold">{{ __('messages.office_ui.policy_sections.shift') }}</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['shift_starts', 'shift_minutes', 'shift_break_minutes', 'shift_min_presence_minutes', 'shift_rest_days'] as $field)
                        <div><span class="text-sm text-gray-600">{{ str_replace(' ('.__('messages.clear_screens.minutes_unit').')', '', __('messages.office_ui.policy_fields.'.$field)) }}</span><strong class="block {{ $field === 'shift_starts' ? 'font-mono' : '' }}"><bdi dir="{{ $field === 'shift_starts' ? 'ltr' : 'auto' }}">{{ $field === 'shift_rest_days' ? implode(', ', array_map(fn ($day) => __('messages.office_ui.weekdays.'.$day), $current->{$field} ?? [])) : (is_array($current->{$field}) ? implode(', ', $current->{$field}) : app(\App\Services\Attendance\Reporting\DurationText::class)->format($current->{$field})) }}</bdi></strong></div>
                    @endforeach
                </div>
                <h3 class="mt-4 font-semibold">{{ __('messages.office_ui.policy_sections.security') }}</h3>
                <p>{{ str_replace(' ('.__('messages.clear_screens.minutes_unit').')', '', __('messages.office_ui.policy_fields.security_min_presence_minutes')) }}: <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($current->security_min_presence_minutes) }}</bdi></p>
                <h3 class="mt-6 font-semibold">{{ __('messages.office_ui.policy_sections.inference') }}</h3>
                <p>{{ str_replace(' ('.__('messages.clear_screens.minutes_unit').')', '', __('messages.office_ui.policy_fields.inference_tolerance_minutes')) }}: <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($current->inference_tolerance_minutes) }}</bdi></p>
                <h3 class="mt-6 font-semibold">{{ __('messages.overtime_ui.policy') }}</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['overtime_min_minutes', 'overtime_step_minutes', 'rest_day_half_minutes', 'rest_day_full_minutes'] as $field)
                        <p>{{ str_replace(' ('.__('messages.clear_screens.minutes_unit').')', '', __('messages.office_ui.policy_fields.'.$field)) }}: <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($current->{$field}) }}</bdi></p>
                    @endforeach
                </div>
                @foreach ($current->compensatory_rest_days ?? [] as $pattern => $days)
                    <p>{{ __('messages.reporting_ui.patterns.'.$pattern) }}: {{ is_array($days) ? implode(', ', array_map(fn ($day) => __('messages.office_ui.weekdays.'.$day), $days)) : __('messages.overtime_ui.individual_rest_days') }}</p>
                @endforeach
            @else <p>{{ __('messages.office_ui.no_policy') }}</p> @endif
        </x-ui.card>
        <x-ui.card :title="__('messages.office_ui.add_version')">
            <form wire:submit="addVersion" class="space-y-4">
                <h3 class="font-semibold">{{ __('messages.office_ui.policy_sections.office') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label>{{ __('messages.office_ui.effective_from') }} <input type="date" wire:model.change="effectiveFrom" dir="ltr" class="block w-full rounded-xl border border-gray-200 p-2"><span class="block text-sm text-gray-600">{{ __('messages.office_ui.effective_from_help') }}</span></label>
                    <label>{{ __('messages.office_ui.policy_fields.office_start') }} <input type="time" wire:model="officeStart" dir="ltr" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.office_ui.policy_fields.flex_minutes') }} <input type="number" wire:model="flexMinutes" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.workday_minutes') }} <input type="number" wire:model="workdayMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.min_presence_minutes') }} <input type="number" wire:model="minPresenceMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.absence_minutes') }} <input type="number" wire:model="absenceMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.close_grace_minutes') }} <input type="number" wire:model="closeGraceMinutes" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.office_ui.policy_fields.minor_gap_tolerance_minutes') }} <input type="number" wire:model="minorGapToleranceMinutes" min="0" max="60" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"><span class="block text-sm text-gray-600">{{ __('messages.office_ui.minor_gap_tolerance_help') }}</span></label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="exitsCountTowardBalance">{{ __('messages.office_ui.policy_fields.exits_count_toward_balance') }}</label>
                </div>
                <fieldset><legend>{{ __('messages.office_ui.policy_fields.office_rest_days') }}</legend><div class="flex flex-wrap gap-3">@foreach (range(1, 7) as $weekday)<label><input type="checkbox" wire:model="officeRestDays" value="{{ $weekday }}"> {{ __('messages.office_ui.weekdays.'.$weekday) }}</label>@endforeach</div></fieldset>
                <h3 class="pt-4 font-semibold">{{ __('messages.office_ui.policy_sections.shift') }}</h3>
                <fieldset><legend>{{ __('messages.office_ui.policy_fields.shift_starts') }}</legend><div class="flex flex-wrap gap-3">@foreach (['00:00', '08:00', '16:00'] as $start)<label><input type="checkbox" wire:model="shiftStarts" value="{{ $start }}"> <bdi dir="ltr">{{ $start }}</bdi></label>@endforeach</div></fieldset>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['shiftMinutes' => 'shift_minutes', 'shiftBreakMinutes' => 'shift_break_minutes', 'shiftMinPresenceMinutes' => 'shift_min_presence_minutes'] as $property => $field)
                        <label>{{ __('messages.office_ui.policy_fields.'.$field) }} <input type="number" wire:model="{{ $property }}" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    @endforeach
                </div>
                <fieldset><legend>{{ __('messages.office_ui.policy_fields.shift_rest_days') }}</legend><div class="flex flex-wrap gap-3">@foreach (range(1, 7) as $weekday)<label><input type="checkbox" wire:model="shiftRestDays" value="{{ $weekday }}"> {{ __('messages.office_ui.weekdays.'.$weekday) }}</label>@endforeach</div></fieldset>
                <h3 class="pt-4 font-semibold">{{ __('messages.office_ui.policy_sections.security') }}</h3>
                <label>{{ __('messages.office_ui.policy_fields.security_min_presence_minutes') }} <input type="number" wire:model="securityMinPresenceMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                <h3 class="pt-4 font-semibold">{{ __('messages.office_ui.policy_sections.inference') }}</h3>
                <label>{{ __('messages.office_ui.policy_fields.inference_tolerance_minutes') }} <input type="number" wire:model="inferenceToleranceMinutes" min="1" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                <h3 class="pt-4 font-semibold">{{ __('messages.overtime_ui.policy') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['overtimeMinMinutes' => 'overtime_min_minutes', 'overtimeStepMinutes' => 'overtime_step_minutes', 'restDayHalfMinutes' => 'rest_day_half_minutes', 'restDayFullMinutes' => 'rest_day_full_minutes'] as $property => $field)
                        <label>{{ __('messages.office_ui.policy_fields.'.$field) }} <input type="number" wire:model="{{ $property }}" min="0" class="block w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    @endforeach
                </div>
                <p class="text-sm text-gray-600">{{ __('messages.overtime_ui.eligible_rest_days') }}: {{ __('messages.overtime_ui.office_friday') }} · {{ __('messages.overtime_ui.shift_friday') }} · {{ __('messages.overtime_ui.security_individual') }}</p>
                <label class="block">{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reason" class="block w-full rounded-xl border border-gray-200 p-2"></label>
                @foreach (['effectiveFrom', 'officeStart', 'flexMinutes', 'workdayMinutes', 'minPresenceMinutes', 'absenceMinutes', 'closeGraceMinutes', 'minorGapToleranceMinutes', 'officeRestDays', 'shiftStarts', 'shiftMinutes', 'shiftBreakMinutes', 'shiftMinPresenceMinutes', 'shiftRestDays', 'securityMinPresenceMinutes', 'inferenceToleranceMinutes', 'overtimeMinMinutes', 'overtimeStepMinutes', 'restDayHalfMinutes', 'restDayFullMinutes', 'reason'] as $field) @error($field) <p class="text-red-600">{{ $message }}</p> @enderror @endforeach
                <x-filament::button type="submit">{{ __('messages.office_ui.add_version') }}</x-filament::button>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('messages.office_ui.history')" padding="none">
            <x-ui.table><thead><tr>@foreach (['version', 'effective_from', 'changes', 'by', 'reason'] as $column)<th>{{ __('messages.office_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
                @forelse ($this->history() as $policy)
                    <tr class="border-t" wire:key="office-policy-{{ $policy->id }}"><x-ui.td :label="__('messages.office_ui.version')"><bdi dir="ltr">{{ $policy->version }}</bdi></x-ui.td><x-ui.td :label="__('messages.office_ui.effective_from')"><bdi dir="ltr">{{ $policy->effective_from->toDateString() }}</bdi></x-ui.td><x-ui.td :label="__('messages.office_ui.changes')">@forelse ($this->changes($policy) as $line)<span class="block">{{ $line }}</span>@empty — @endforelse</x-ui.td><x-ui.td :label="__('messages.office_ui.by')">{{ $policy->creator?->name ?? __('messages.office_ui.system') }}</x-ui.td><x-ui.td :label="__('messages.office_ui.reason')">{{ str_starts_with((string) $policy->reason, 'office_rest_hour_480_from_policy:') ? __('messages.office_ui.office_rest_replacement_reason') : (str_starts_with((string) $policy->reason, 'office_presence_540_from_policy:') ? __('messages.office_ui.office_presence_replacement_reason') : ($policy->reason ?? '—')) }}</x-ui.td></tr>
                @empty <tr><td colspan="4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table><div class="p-3">{{ $this->history()->links() }}</div>
        </x-ui.card>
    </div>
</x-filament-panels::page>
