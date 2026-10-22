<x-filament-panels::page>
    @php $day = $this->day(); $latest = $this->latestReview($day); @endphp
    <div class="space-y-5">
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-bold">{{ $this->employee()->name }} <bdi class="font-mono text-sm" dir="ltr">{{ $this->employee()->employee_code }}</bdi></h2><p><bdi dir="ltr">{{ $day->work_date->toDateString() }}</bdi> · {{ $day->branch?->name ?? '—' }}</p></div>
                <x-ui.badge :tone="match ($day->status) { 'present' => 'green', 'absent', 'device_outage' => 'red', 'missing_punch', 'pending' => 'amber', default => 'gray' }">{{ __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['first_in', 'last_out', 'required_leave_at'] as $field)
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $day->{$field}?->format('H:i') ?? '—' }}</strong></div>
                @endforeach
                @foreach (['elapsed_minutes', 'presence_minutes', 'late_minutes', 'early_minutes', 'exit_minutes', 'short_minutes', 'balance_minutes'] as $field)
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.minutes.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $this->minutes($day->{$field}) }}</strong></div>
                @endforeach
                <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.reviewed') }}</span><strong class="block">{{ $day->reviewed ? __('messages.office_ui.yes') : __('messages.office_ui.no') }}</strong></div>
            </div>
        </x-ui.card>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.card :title="__('messages.office_ui.intervals')">
                @forelse ($day->intervals ?? [] as $interval)<p class="font-mono" dir="ltr">{{ substr($interval['from'], 11, 5) }} → {{ substr($interval['to'], 11, 5) }}</p>@empty <p>—</p> @endforelse
            </x-ui.card>
            <x-ui.card :title="__('messages.office_ui.exits')">
                @forelse ($day->exits ?? [] as $exit)<p><bdi class="font-mono" dir="ltr">{{ substr($exit['from'], 11, 5) }} → {{ substr($exit['to'], 11, 5) }} ({{ $this->minutes($exit['minutes']) }})</bdi></p>@empty <p>—</p> @endforelse
            </x-ui.card>
        </div>
        <x-ui.card :title="__('messages.office_ui.rule_inputs')">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['policy_version', 'work_pattern', 'hire_date', 'exit_status', 'last_working_day', 'branch_id', 'schedule_start', 'schedule_end', 'rest_days', 'reason'] as $field)
                    @if (array_key_exists($field, $day->inputs ?? []))<div><span class="text-sm text-gray-600">{{ __('messages.office_ui.inputs.'.$field) }}</span><strong class="block break-all font-mono text-sm" dir="ltr">{{ $this->displayValue($day->inputs[$field]) }}</strong></div>@endif
                @endforeach
                @if ($day->policy)<div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.office_start') }}</span><strong class="block font-mono" dir="ltr">{{ $day->policy->office_start }}</strong></div>
                    @foreach (['flex_minutes', 'workday_minutes', 'min_presence_minutes', 'close_grace_minutes', 'exits_count_toward_balance', 'office_rest_days'] as $field)
                        <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $this->displayValue($day->policy->{$field}) }}</strong></div>
                    @endforeach
                @endif
            </div>
        </x-ui.card>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.card :title="__('messages.office_ui.manual_punches')">
                @forelse ($this->manualPunches() as $punch)<p class="border-b py-2"><bdi dir="ltr" class="font-mono">{{ $punch->punched_at->format('H:i') }}</bdi> · {{ __('messages.office_ui.'.$punch->role) }} · {{ $punch->reason }} · {{ $punch->reverses_id ? __('messages.office_ui.withdrawn') : __('messages.office_ui.by') }} {{ $punch->creator?->name ?? '—' }}</p>@empty <p>—</p> @endforelse
            </x-ui.card>
            <x-ui.card :title="__('messages.office_ui.decisions')">
                @forelse ($this->decisions() as $decision)<p class="border-b py-2">{{ __('messages.office_ui.decisions_list.'.$decision->decision) }} · {{ $decision->reason }} · {{ __('messages.office_ui.by') }} {{ $decision->creator?->name ?? '—' }}</p>@empty <p>—</p> @endforelse
            </x-ui.card>
        </div>
        @if ($day->status === 'missing_punch' || ($day->status === 'absent' && ! $day->reviewed) || $latest)
            <x-ui.card :title="__('messages.office_ui.review_action')">
                @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent'], true))
                    <button class="underline" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.review_action') }}</button>
                @endif
                @if ($latest)
                    <button class="ms-3 underline" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.withdraw') }}</button>
                @endif
                @if ($reviewDayId === $day->id)
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent'], true))
                            <label>{{ __('messages.office_ui.role') }} <select wire:model="reviewRole" class="rounded-xl border border-gray-200 p-2"><option value="check_in">{{ __('messages.office_ui.check_in') }}</option><option value="check_out">{{ __('messages.office_ui.check_out') }}</option></select></label>
                            <label>{{ __('messages.office_ui.time') }} <input type="time" wire:model="reviewTime" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                        @endif
                        <label>{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reviewReason" class="rounded-xl border border-gray-200 p-2"></label>
                    </div>
                    @error('reviewTime') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewReason') <p class="text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent'], true))<x-filament::button wire:click="addMissingTime">{{ __('messages.office_ui.add_time') }}</x-filament::button><button class="underline" wire:click="confirmAbsence">{{ __('messages.office_ui.confirm_absence') }}</button>@endif
                        @if ($latest)<button class="underline" wire:click="withdrawLatest({{ $day->id }})">{{ __('messages.office_ui.confirm_withdraw') }}</button>@endif
                    </div>
                @endif
            </x-ui.card>
        @endif
        <x-ui.card :title="__('messages.office_ui.recalculation_history')">
            @foreach ($this->versions() as $version)
                <div class="border-b py-3" wire:key="office-version-{{ $version->id }}"><strong><bdi dir="ltr">#{{ $version->run_id }}</bdi> · {{ $this->triggerLabel($version->run?->trigger_source) }} · <bdi dir="ltr">{{ $version->created_at?->format('Y-m-d H:i') }}</bdi></strong>
                    @forelse ($version->changes ?? [] as $field => $change)
                        <p class="text-sm">{{ __('messages.office_ui.change_fields.'.$field) }}: <bdi dir="{{ $field === 'status' ? 'auto' : 'ltr' }}">{{ $this->changeValue($field, $change['before'] ?? null) }}</bdi> → <bdi dir="{{ $field === 'status' ? 'auto' : 'ltr' }}">{{ $this->changeValue($field, $change['after'] ?? null) }}</bdi></p>
                    @empty <p>—</p> @endforelse
                </div>
            @endforeach
            {{ $this->versions()->links() }}
        </x-ui.card>
    </div>
</x-filament-panels::page>
