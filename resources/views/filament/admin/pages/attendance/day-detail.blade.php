<x-filament-panels::page>
    @php $day = $this->day(); $latest = $this->latestReview($day); $overtime = $this->overtime(); $entitlement = $this->entitlement(); @endphp
    <div class="space-y-5">
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-bold">{{ $this->employee()->name }} <bdi class="font-mono text-sm" dir="ltr">{{ $this->employee()->employee_code }}</bdi></h2><p><bdi dir="ltr">{{ $day->work_date->toDateString() }}</bdi> · {{ $day->branch?->name ?? '—' }}</p></div>
                <x-ui.badge :tone="match ($day->status) { 'present' => 'green', 'absent', 'device_outage' => 'red', 'missing_punch', 'pending' => 'amber', default => 'gray' }">{{ __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                @if (in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true))
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.shift') }}</span><strong class="block font-mono" dir="ltr">{{ \App\Services\Attendance\ShiftDisplay::hours($day->shift_start, $day->policy?->shift_minutes) ?? '—' }}</strong></div>
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.shift_source') }}</span><strong class="block">{{ __('messages.office_ui.shift_sources.'.($day->shift_source ?? 'unknown')) }}</strong></div>
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.shift_confidence') }}</span><strong class="block">{{ __('messages.office_ui.shift_confidences.'.($day->shift_confidence ?? 'unresolved')) }}</strong></div>
                @endif
                @foreach (['first_in', 'last_out', 'required_leave_at'] as $field)
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $day->{$field}?->format('H:i') ?? '—' }}</strong></div>
                @endforeach
                @foreach (['elapsed_minutes', 'presence_minutes', 'late_minutes', 'early_minutes', 'exit_minutes', 'short_minutes', 'balance_minutes'] as $field)
                    <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.minutes.'.$field) }}</span><strong class="block font-mono" dir="ltr">{{ $this->minutes($day->{$field}) }}</strong></div>
                @endforeach
                <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.reviewed') }}</span><strong class="block">{{ $day->reviewed ? __('messages.office_ui.yes') : __('messages.office_ui.no') }}</strong></div>
                <div><span class="text-sm text-gray-600">{{ __('messages.overtime_ui.overtime') }}</span><strong class="block font-mono" dir="ltr">{{ $this->minutes($overtime['overtime_minutes'] ?? null) }}</strong>@if ($overtime['overtime_basis'] ?? null)<span>{{ __('messages.overtime_ui.basis.'.$overtime['overtime_basis']) }}</span>@endif</div>
                @if (($overtime['early_arrival_minutes'] ?? 0) > 0)<div><span class="text-sm text-gray-600">{{ __('messages.overtime_ui.early_marker') }}</span><strong class="block font-mono" dir="ltr">{{ $this->minutes($overtime['early_arrival_minutes']) }}</strong></div>@endif
                @if ($entitlement)<div><span class="text-sm text-gray-600">{{ __('messages.overtime_ui.entitlement') }}</span><a class="ui-link block font-mono" dir="ltr" href="{{ route('employees.show', $this->employee()).'?attendance_tab=overtime' }}">{{ number_format($entitlement['earned'], 1) }}</a><span>{{ __('messages.overtime_ui.entitlement_basis.'.$entitlement['basis']) }}</span></div>@endif
            </div>
        </x-ui.card>
        @if ($day->holiday_ids)
            <x-ui.card :title="__('messages.holiday_ui.holiday_names')">
                @foreach ($this->holidayNames() as $holidayId => $name)
                    <a class="ui-link block" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['holiday' => $holidayId]) }}">{{ $name }}</a>
                @endforeach
            </x-ui.card>
        @endif
        @if (in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true))
            <x-ui.card :title="__('messages.office_ui.shift_evidence')">
                @foreach (['first_in', 'last_out', 'approved_leave_minutes'] as $field)
                    <p>{{ __('messages.office_ui.shift_evidence_fields.'.$field) }}: <bdi dir="ltr">{{ $day->shift_evidence[$field] ?? '—' }}</bdi></p>
                @endforeach
            </x-ui.card>
        @endif
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
                    @if (array_key_exists($field, $day->inputs ?? []))<div><span class="text-sm text-gray-600">{{ __('messages.office_ui.inputs.'.$field) }}</span><strong class="block break-words font-mono text-sm" dir="auto">{{ $this->displayValue($day->inputs[$field], $field) }}</strong></div>@endif
                @endforeach
                @if ($day->policy)<div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.office_start') }}</span><strong class="block font-mono" dir="ltr">{{ $day->policy->office_start }}</strong></div>
                    @foreach (['flex_minutes', 'workday_minutes', 'min_presence_minutes', 'absence_minutes', 'close_grace_minutes', 'exits_count_toward_balance', 'office_rest_days', 'shift_starts', 'shift_minutes', 'shift_break_minutes', 'shift_min_presence_minutes', 'shift_rest_days', 'security_min_presence_minutes', 'inference_tolerance_minutes', 'overtime_min_minutes', 'overtime_step_minutes', 'rest_day_half_minutes', 'rest_day_full_minutes', 'compensatory_rest_days'] as $field)
                        <div><span class="text-sm text-gray-600">{{ __('messages.office_ui.policy_fields.'.$field) }}</span><strong class="block break-words font-mono" dir="auto">{{ $this->displayValue($day->policy->{$field}, $field) }}</strong></div>
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
        @if (in_array($day->status, ['missing_punch', 'shift_unresolved'], true) || ($day->status === 'absent' && ! $day->reviewed) || $latest)
            <x-ui.card :title="__('messages.office_ui.review_action')">
                @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent', 'shift_unresolved'], true))
                    <button class="underline" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.review_action') }}</button>
                @endif
                @if ($latest)
                    <button class="ms-3 underline" wire:click="selectReviewDay({{ $day->id }})">{{ ($latest['shift'] ?? false) ? __('messages.office_ui.withdraw_shift') : __('messages.office_ui.withdraw') }}</button>
                @endif
                @if ($reviewDayId === $day->id)
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent', 'shift_unresolved'], true))
                            <label>{{ __('messages.office_ui.role') }} <select wire:model="reviewRole" class="rounded-xl border border-gray-200 p-2"><option value="check_in">{{ __('messages.office_ui.check_in') }}</option><option value="check_out">{{ __('messages.office_ui.check_out') }}</option></select></label>
                            <label>{{ __('messages.office_ui.time') }} <input type="time" wire:model="reviewTime" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                            @if ($reviewIsShift)<label>{{ __('messages.office_ui.shift') }} <select wire:model="reviewShiftStart" dir="ltr" class="rounded-xl border border-gray-200 p-2">@foreach ($reviewShiftStarts as $start)<option value="{{ substr($start, 0, 5) }}">{{ substr($start, 0, 5) }}</option>@endforeach</select></label>@endif
                        @endif
                        <label>{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reviewReason" class="rounded-xl border border-gray-200 p-2"></label>
                    </div>
                    @error('reviewTime') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewShiftStart') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewReason') <p class="text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if (! $day->reviewed && in_array($day->status, ['missing_punch', 'absent', 'shift_unresolved'], true))<x-filament::button wire:click="addMissingTime">{{ __('messages.office_ui.add_time') }}</x-filament::button><button class="underline" wire:click="confirmAbsence">{{ __('messages.office_ui.confirm_absence') }}</button>@if ($reviewIsShift)<button class="underline" wire:click="pickShift">{{ __('messages.office_ui.pick_shift') }}</button>@endif@endif
                        @if ($latest)<button class="underline" wire:click="withdrawLatest({{ $day->id }})">{{ ($latest['shift'] ?? false) ? __('messages.office_ui.withdraw_shift') : __('messages.office_ui.confirm_withdraw') }}</button>@endif
                    </div>
                @endif
            </x-ui.card>
        @endif
        <x-ui.card :title="__('messages.office_ui.recalculation_history')">
            @foreach ($this->versions() as $version)
                <div class="border-b py-3" wire:key="office-version-{{ $version->id }}"><strong><bdi dir="ltr">#{{ $version->run_id }}</bdi> · {{ $this->triggerLabel($version->run?->trigger_source) }} · <bdi dir="ltr">{{ $version->created_at?->format('Y-m-d H:i') }}</bdi></strong>
                    @forelse ($version->changes ?? [] as $field => $change)
                        <p class="text-sm break-words">{{ __('messages.office_ui.change_fields.'.$field) }}: <bdi dir="{{ $this->changeDir($field) }}">{{ $this->changeValue($field, $change['before'] ?? null) }}</bdi> {{ $this->changeArrow() }} <bdi dir="{{ $this->changeDir($field) }}">{{ $this->changeValue($field, $change['after'] ?? null) }}</bdi></p>
                    @empty <p>—</p> @endforelse
                </div>
            @endforeach
            {{ $this->versions()->links() }}
        </x-ui.card>
        <x-ui.card :title="__('messages.leave_ui.day_reconciliation')">
            @php $reconciliation = $this->leaveReconciliation(); @endphp
            @foreach (['absence', 'late', 'early', 'exit'] as $kind)
                <p>{{ app()->getLocale() === 'en' ? ucfirst(__('messages.leave_ui.target_kinds.'.$kind)) : __('messages.leave_ui.target_kinds.'.$kind) }}: {{ __('messages.leave_ui.reconciled') }} <bdi dir="ltr">{{ $this->minutes($reconciliation['reconciled'][$kind] ?? 0) }}</bdi> · {{ __('messages.leave_ui.remaining') }} <bdi dir="ltr">{{ $this->minutes($reconciliation['remaining'][$kind] ?? 0) }}</bdi></p>
            @endforeach
            @if (($reconciliation['absence_closed'] ?? false) && $reconciliation['closed_by_request'])
                <p class="font-semibold">{{ __('messages.leave_ui.absence_closed', ['request' => "\u{2066}".$reconciliation['closed_by_request']."\u{2069}"]) }}</p>
            @elseif ($reconciliation['absence_closed'] ?? false)
                <p class="font-semibold">{{ __('messages.leave_ui.absence_reconciled') }}</p>
            @endif
            @foreach ($reconciliation['explanations'] ?? [] as $explanation)
                <p>{{ app(\App\Services\Leave\LeaveExplanation::class)->render($explanation) }}</p>
            @endforeach
            @foreach ($reconciliation['pending_requests'] ?? [] as $pending)
                <p>{{ __('messages.leave_ui.pending_request', ['request' => "\u{2066}".$pending['external_id']."\u{2069}", 'approver' => $pending['approver_name'] ?? '']) }}</p>
            @endforeach
        </x-ui.card>
        @if ($case = $this->followUpCase())
            <x-ui.card :title="__('messages.follow_up_ui.cases')">
                <p>{{ __('messages.follow_up_ui.states.'.$case->state) }}</p>
                <p>@foreach ($case->items ?? [] as $item){{ __('messages.follow_up_ui.items.'.$item['type']) }} @if ($item['minutes'] !== null)<bdi dir="ltr">{{ $this->minutes($item['minutes']) }}</bdi>@endif · @endforeach</p>
                <p>{{ __('messages.follow_up_ui.routes_title') }}: @foreach ($case->routes ?? [] as $route){{ __('messages.follow_up_ui.routes.'.$route['kind']) }} @if ($route['external_id'] ?? null)<bdi dir="ltr">{{ $route['external_id'] }}</bdi>@endif @if ($routeReason = app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->routeReason($route)){{ $routeReason }}@endif · @endforeach</p>
                @foreach ($case->deliveryRoutes as $delivery)<p>{{ __('messages.follow_up_ui.last_sent') }}: <bdi dir="ltr">{{ $delivery->last_sent_at ?? '—' }}</bdi> · {{ __('messages.follow_up_ui.reminders_sent') }}: <bdi dir="ltr">{{ max(0, $delivery->sent_count - 1) }}</bdi></p>@endforeach
                @if ($case->closure_source) <p>{{ __('messages.follow_up_ui.closure_source') }}: {{ __('messages.follow_up_ui.closure_kinds.'.($case->closure_source['kind'] ?? 'reassessment')) }} @foreach (app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->closureRequests($case) as $request)<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['request' => $request->id]) }}"><bdi dir="ltr">{{ $request->external_id }}</bdi></a> @endforeach</p> @endif
                @if (($case->closure_source['kind'] ?? null) === 'manual' && $case->closure_reason) <p>{{ __('messages.follow_up_ui.reason') }}: {{ $case->closure_reason }}</p> @endif
                <details><summary>{{ __('messages.follow_up_ui.event_history') }}</summary>@foreach ($case->events as $event)<p><bdi dir="ltr">{{ $event->created_at }}</bdi> · {{ __('messages.follow_up_ui.events.'.$event->event) }}</p>@endforeach</details>
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
