<x-filament-panels::page>
    @php $request = $this->record(); $effective = $this->effectiveValues(); @endphp
    <div class="space-y-5">
        <x-ui.card :title="__('messages.leave_ui.detail_title')">
            <h2 class="font-bold"><bdi dir="ltr">{{ $request->external_id }}</bdi> · {{ $request->employee?->name ?? __('messages.leave_ui.unknown_employee') }}</h2>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <p>{{ __('messages.leave_ui.status') }}: {{ __('messages.leave_ui.statuses.'.$effective['status']) }}</p>
                <p>{{ __('messages.leave_ui.type') }}: {{ $request->leaveType?->name ?? '—' }}</p>
                <p>{{ __('messages.leave_ui.dates') }}: <bdi dir="ltr">{{ $effective['start_date'] }} – {{ $effective['end_date'] }}</bdi></p>
                <p>{{ __('messages.leave_ui.duration') }}: <bdi dir="ltr">{{ $effective['duration_days'] ?? '—' }}</bdi></p>
                <p>{{ __('messages.leave_ui.counting_method') }}: {{ $request->counting_method === null ? '—' : __('messages.leave_ui.counting_methods.'.$request->counting_method) }}</p>
                <p>{{ __('messages.leave_ui.approver') }}: {{ $request->approver_name ?? '—' }}</p>
                <p>{{ __('messages.leave_ui.snapshot_at') }}: <bdi dir="ltr">{{ $request->latest_snapshot_at?->format('Y-m-d H:i:s') }}</bdi></p>
            </div>
            @foreach ($request->flags ?? [] as $flag)<span class="me-2 text-amber-700">{{ $this->errorLabel($flag) }}</span>@endforeach
            @foreach ($this->issues() as $issue)<span class="me-2 text-amber-700">{{ __('messages.leave_ui.issue_types.'.$issue->type) }}</span>@endforeach
        </x-ui.card>
        <x-ui.card :title="__('messages.leave_ui.versions')">
            @foreach ($this->versions() as $version)
                <p class="border-b py-2" wire:key="leave-version-{{ $version->id }}"><bdi dir="ltr">{{ $version->snapshot_at->format('Y-m-d H:i:s') }} · #{{ $version->batch_id }}</bdi>
                    {{ __('messages.leave_ui.statuses.'.($version->values['status'] ?? 'pending')) }} · {{ __('messages.leave_ui.changed_fields') }}:
                    @foreach ($this->changedFields($version) as $field) {{ $field }}@if (! $loop->last), @endif @endforeach
                </p>
            @endforeach
            {{ $this->versions()->links() }}
        </x-ui.card>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.card :title="__('messages.leave_ui.distributed_days')">
                @forelse ($this->days() as $day)<p><bdi dir="ltr">{{ $day->work_date->toDateString() }} · {{ $this->minutes($day->minutes) }}</bdi></p>@empty <p>—</p> @endforelse
            </x-ui.card>
            <x-ui.card :title="__('messages.leave_ui.credits_title')">
                @forelse ($this->credits() as $credit)
                    <p><bdi dir="ltr">{{ $credit->sourceDay->work_date->toDateString() }}</bdi> · {{ __('messages.leave_ui.minutes') }} <bdi dir="ltr">{{ $this->minutes($credit->minutes) }}</bdi> · {{ __('messages.leave_ui.available') }} <bdi dir="ltr">{{ $this->minutes($credit->minutes - $this->creditUsed($credit->id)) }}</bdi></p>
                @empty <p>—</p> @endforelse
            </x-ui.card>
        </div>
        <x-ui.card :title="__('messages.leave_ui.allocations')">
            @forelse ($this->allocations() as $allocation)
                <p class="border-b py-2"><bdi dir="ltr">{{ $allocation->targetDay?->work_date?->toDateString() }}</bdi> · {{ app(\App\Services\Leave\LeaveExplanation::class)->render($allocation->explanation) }}</p>
            @empty <p>—</p> @endforelse
        </x-ui.card>
        <x-ui.card :title="__('messages.leave_ui.actions')">
            <div class="space-y-3">
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                <div><x-filament::button wire:click="cancel">{{ __('messages.leave_ui.cancel') }}</x-filament::button></div>
                <div class="flex flex-wrap gap-3"><label>{{ __('messages.leave_ui.distribution_mode') }} <select wire:model="distributionMode" class="rounded-xl border border-gray-200 p-2"><option value="from_start">{{ __('messages.leave_ui.from_start') }}</option><option value="dates">{{ __('messages.leave_ui.pick_dates') }}</option></select></label>
                    <label>{{ __('messages.leave_ui.pick_dates') }} <input type="text" wire:model="distributionDates" dir="ltr" placeholder="{{ __('messages.leave_ui.dates_format') }}" class="rounded-xl border border-gray-200 p-2"></label>
                    <x-filament::button wire:click="confirmDistribution">{{ __('messages.leave_ui.confirm_distribution') }}</x-filament::button></div>
                <div class="flex flex-wrap gap-3"><label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model="startDate" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model="endDate" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.duration') }} <input type="number" step="0.5" wire:model="durationDays" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <x-filament::button wire:click="fixDates">{{ __('messages.leave_ui.fix_dates') }}</x-filament::button></div>
            </div>
        </x-ui.card>
        <x-ui.card :title="__('messages.leave_ui.decisions')">
            @forelse ($this->decisions() as $decision)
                <p class="border-b py-2" wire:key="leave-decision-{{ $decision->id }}">{{ __('messages.leave_ui.decision_kinds.'.$decision->decision) }} · {{ $decision->reason }} · <bdi dir="ltr">{{ $decision->created_at->format('Y-m-d H:i') }}</bdi>
                    @if ($decision->decision === 'confirm_distribution')
                        · {{ __('messages.leave_ui.distribution_mode') }}: {{ __('messages.leave_ui.'.($decision->values['mode'] === 'dates' ? 'pick_dates' : 'from_start')) }}
                        @if ($decision->values['dates']) <bdi dir="ltr">{{ implode(', ', $decision->values['dates']) }}</bdi> @endif
                    @elseif ($decision->decision === 'fix_dates')
                        · <bdi dir="ltr">{{ $decision->values['start_date'] }} – {{ $decision->values['end_date'] }} · {{ $decision->values['duration_days'] }}</bdi>
                    @elseif ($decision->decision === 'withdraw')
                        · {{ __('messages.leave_ui.withdraws_decision') }} <bdi dir="ltr">#{{ $decision->values['decision_id'] }}</bdi>
                    @endif
                    @if ($decision->decision !== 'withdraw' && ! $this->isWithdrawn($decision))<button class="ms-3 underline" wire:click="withdraw({{ $decision->id }})">{{ __('messages.leave_ui.withdraw') }}</button>@endif</p>
            @empty <p>—</p> @endforelse
        </x-ui.card>
    </div>
</x-filament-panels::page>
