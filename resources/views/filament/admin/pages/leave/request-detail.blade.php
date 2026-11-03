<x-filament-panels::page>
    @php $request = $this->record(); $effective = $this->effectiveValues(); @endphp
    <div class="space-y-5">
        <x-ui.card :title="__('messages.leave_ui.detail_title')">
            <h2 class="font-bold"><bdi dir="ltr">{{ $request->external_id }}</bdi> · {{ $request->employee?->name ?? __('messages.leave_ui.unknown_employee') }}</h2>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <p>{{ __('messages.leave_ui.status') }}: <x-ui.badge :tone="match ($effective['status']) { 'approved' => 'green', 'rejected' => 'red', 'pending' => 'indigo', default => 'gray' }">{{ __('messages.leave_ui.statuses.'.$effective['status']) }}</x-ui.badge></p>
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
        <x-ui.card :title="__('messages.leave_ui.actions')">
            <div class="space-y-3">
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                <div><x-filament::button color="gray" wire:click="cancel">{{ __('messages.leave_ui.cancel') }}</x-filament::button></div>
                <div class="flex flex-wrap gap-3"><label>{{ __('messages.leave_ui.distribution_mode') }} <select wire:model="distributionMode" class="rounded-xl border border-gray-200 p-2"><option value="from_start">{{ __('messages.leave_ui.from_start') }}</option><option value="dates">{{ __('messages.leave_ui.pick_dates') }}</option></select></label>
                    <label>{{ __('messages.leave_ui.pick_dates') }} <input type="text" wire:model="distributionDates" dir="ltr" placeholder="{{ __('messages.leave_ui.dates_format') }}" class="rounded-xl border border-gray-200 p-2"></label>
                    <x-filament::button wire:click="confirmDistribution">{{ __('messages.leave_ui.confirm_distribution') }}</x-filament::button></div>
                <div class="flex flex-wrap gap-3"><label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model="startDate" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model="endDate" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.duration') }} <input type="number" step="0.5" wire:model="durationDays" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <x-filament::button color="gray" wire:click="fixDates">{{ __('messages.leave_ui.fix_dates') }}</x-filament::button></div>
            </div>
        </x-ui.card>
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.leave_ui.versions') }}</summary>
        <x-ui.card :title="__('messages.leave_ui.versions')">
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.leave_ui.snapshot_at') }}</th><th scope="col">{{ __('messages.leave_ui.status') }}</th><th scope="col">{{ __('messages.leave_ui.changed_fields') }}</th></tr></thead><tbody>
            @forelse ($this->versions() as $version)
                <tr wire:key="leave-version-{{ $version->id }}"><x-ui.td :label="__('messages.leave_ui.snapshot_at')"><bdi dir="ltr">{{ $version->snapshot_at->format('Y-m-d H:i:s') }} · #{{ $version->batch_id }}</bdi></x-ui.td><x-ui.td :label="__('messages.leave_ui.status')"><x-ui.badge :tone="match ($version->values['status'] ?? 'pending') { 'approved' => 'green', 'rejected' => 'red', 'pending' => 'indigo', default => 'gray' }">{{ __('messages.leave_ui.statuses.'.($version->values['status'] ?? 'pending')) }}</x-ui.badge></x-ui.td><x-ui.td :label="__('messages.leave_ui.changed_fields')">@foreach ($this->changedFields($version) as $field) {{ $field }}@if (! $loop->last), @endif @endforeach</x-ui.td></tr>
            @empty <tr><td colspan="3" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table>
            {{ $this->versions()->links() }}
        </x-ui.card></details>
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.card :title="__('messages.leave_ui.distributed_days')">
                <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.leave_ui.duration') }}</th></tr></thead><tbody>
                @forelse ($this->days() as $day)<tr><x-ui.td :label="__('messages.office_ui.date')">{{ $day->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.duration')"><bdi dir="auto">{{ $this->minutes($day->minutes) }}</bdi></x-ui.td></tr>@empty <tr><td colspan="2" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
                </tbody></x-ui.table>
            </x-ui.card>
            <x-ui.card :title="__('messages.leave_ui.credits_title')">
                <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.leave_ui.minutes') }}</th><th scope="col">{{ __('messages.leave_ui.available') }}</th></tr></thead><tbody>
                @forelse ($this->credits() as $credit)
                    <tr><x-ui.td :label="__('messages.office_ui.date')">{{ $credit->sourceDay->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.minutes')"><bdi dir="auto">{{ $this->minutes($credit->minutes) }}</bdi></x-ui.td><x-ui.td :label="__('messages.leave_ui.available')"><bdi dir="auto">{{ $this->minutes($credit->minutes - $this->creditUsed($credit->id)) }}</bdi></x-ui.td></tr>
                @empty <tr><td colspan="3" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
                </tbody></x-ui.table>
            </x-ui.card>
        </div>
        <x-ui.card :title="__('messages.leave_ui.allocations')">
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.leave_ui.allocations') }}</th></tr></thead><tbody>
            @forelse ($this->allocations() as $allocation)
                <tr><x-ui.td :label="__('messages.office_ui.date')">{{ $allocation->targetDay?->work_date?->locale(app()->getLocale())->translatedFormat('l j F Y') ?? '—' }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.allocations')">{{ app(\App\Services\Leave\LeaveExplanation::class)->render($allocation->explanation) }}</x-ui.td></tr>
            @empty <tr><td colspan="2" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        </x-ui.card>
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.leave_ui.decisions') }}</summary>
        <x-ui.card :title="__('messages.leave_ui.decisions')">
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.leave_ui.decisions') }}</th><th scope="col">{{ __('messages.leave_ui.actions') }}</th></tr></thead><tbody>
            @forelse ($this->decisions() as $decision)
                <tr wire:key="leave-decision-{{ $decision->id }}"><x-ui.td :label="__('messages.office_ui.date')"><bdi dir="ltr">{{ $decision->created_at->format('Y-m-d H:i') }}</bdi></x-ui.td><x-ui.td :label="__('messages.leave_ui.decisions')">{{ __('messages.leave_ui.decision_kinds.'.$decision->decision) }} · {{ $decision->reason }}
                    @if ($decision->decision === 'confirm_distribution')
                        · {{ __('messages.leave_ui.distribution_mode') }}: {{ __('messages.leave_ui.'.($decision->values['mode'] === 'dates' ? 'pick_dates' : 'from_start')) }}
                        @if ($decision->values['dates']) <bdi dir="ltr">{{ implode(', ', $decision->values['dates']) }}</bdi> @endif
                    @elseif ($decision->decision === 'fix_dates')
                        · <bdi dir="ltr">{{ $decision->values['start_date'] }} – {{ $decision->values['end_date'] }} · {{ $decision->values['duration_days'] }}</bdi>
                    @elseif ($decision->decision === 'withdraw')
                        · {{ __('messages.leave_ui.withdraws_decision') }} <bdi dir="ltr">#{{ $decision->values['decision_id'] }}</bdi>
                    @endif
                    </x-ui.td><x-ui.td :label="__('messages.leave_ui.actions')">@if ($decision->decision !== 'withdraw' && ! $this->isWithdrawn($decision))<button class="ui-link" wire:click="withdraw({{ $decision->id }})">{{ __('messages.leave_ui.withdraw') }}</button>@else — @endif</x-ui.td></tr>
            @empty <tr><td colspan="3" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        </x-ui.card></details>
    </div>
</x-filament-panels::page>
