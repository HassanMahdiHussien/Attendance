<x-filament-panels::page>
    @php $counts = $this->counts(); @endphp
    <x-ui.card>
        <p class="text-lg font-semibold">{{ __('messages.leave_descriptions.progress', ['read' => $counts['read'], 'total' => $counts['total']]) }}</p>
        <p class="text-sm text-gray-600">{{ __('messages.leave_descriptions.failures', ['count' => $counts['failures']]) }}</p>
        @if ($counts['failures'] > 0)<button type="button" wire:click="retryAll" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.retry_all', ['count' => $counts['failures']]) }}</button>@endif
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            @foreach ($this->categories() as $name)
                <span class="rounded border border-line px-2 py-1">{{ __('messages.leave_descriptions.categories.'.$name) }}: {{ $counts['categories'][$name] ?? 0 }}</span>
            @endforeach
            @foreach (['proposed', 'confirmed', 'rejected', 'not_needed'] as $name)
                <span class="rounded border border-line px-2 py-1">{{ __('messages.leave_descriptions.statuses.'.$name) }}: {{ $counts['statuses'][$name] ?? 0 }}</span>
            @endforeach
        </div>
    </x-ui.card>
    <x-ui.card>
        <div class="flex flex-wrap gap-3">
            <label>{{ __('messages.leave_descriptions.category') }} <select wire:model.live="category" class="ui-control rounded-lg border border-line p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->categories() as $name)<option value="{{ $name }}">{{ __('messages.leave_descriptions.categories.'.$name) }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_descriptions.status') }} <select wire:model.live="status" class="ui-control rounded-lg border border-line p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach (['proposed', 'confirmed', 'rejected', 'not_needed', 'failed'] as $name)<option value="{{ $name }}">{{ __('messages.leave_descriptions.statuses.'.$name) }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_descriptions.branch') }} <select wire:model.live="branchId" class="ui-control rounded-lg border border-line p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model.live="from" dir="ltr" class="ui-control rounded-lg border border-line p-2"></label>
            <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model.live="to" dir="ltr" class="ui-control rounded-lg border border-line p-2"></label>
        </div>
        @if ($this->confirmableCount() > 0)
            <button type="button" wire:click="confirmAll" class="mt-4 rounded-lg bg-amber-400 px-4 py-2 text-night">{{ __('messages.leave_descriptions.confirm_all', ['count' => $this->confirmableCount()]) }}</button>
        @endif
    </x-ui.card>
    <div class="space-y-4">
        @forelse ($this->readings() as $reading)
            @php
                $request = $reading->request;
                $description = $request?->currentVersion?->values['description'] ?? '';
                $reason = $this->effectReason($reading);
            @endphp
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $request?->employee?->name ?? __('messages.leave_ui.unknown_employee') }} · <bdi dir="ltr">{{ $request?->external_id }}</bdi></p>
                        <p class="text-sm text-gray-600">{{ $request?->leaveType?->name }} · {{ __('messages.leave_ui.statuses.'.$request?->status) }} · <bdi dir="ltr">{{ $request?->start_date?->toDateString() }} – {{ $request?->end_date?->toDateString() }}</bdi> · {{ $request?->duration_days }}</p>
                    </div>
                    <span class="text-sm">{{ __('messages.leave_descriptions.statuses.'.$reading->status) }}</span>
                </div>
                <p class="mt-3 whitespace-pre-wrap break-words" dir="auto">{{ $description }}</p>
                <p class="mt-3 text-sm">{{ __('messages.leave_descriptions.understood') }}: {{ $reading->category ? __('messages.leave_descriptions.categories.'.$reading->category) : __('messages.leave_descriptions.waiting') }}@if ($reading->worked_dates) · <bdi dir="ltr">{{ implode(', ', $reading->worked_dates) }}</bdi> @endif @if ($reading->settled_from) · <bdi dir="ltr">{{ $reading->settled_from->toDateString() }} – {{ $reading->settled_to?->toDateString() }}</bdi> @endif</p>
                <p class="text-sm">{{ __('messages.leave_descriptions.effect') }}: @if ($reason) {{ __('messages.leave_descriptions.effect_reasons.'.$reason) }} @elseif ($reading->status === 'confirmed' && $reading->category === 'compensation_for_day') {{ __('messages.leave_descriptions.uses_rest_credit', ['date' => implode(', ', $reading->worked_dates ?? [])]) }} @elseif ($reading->status === 'confirmed' && $reading->category === 'hourly_settlement') {{ __('messages.leave_descriptions.uses_period') }} @else {{ __('messages.leave_descriptions.no_effect_until_confirmed') }} @endif</p>
                @if ($reading->category === 'compensation_for_day' && ! $reason && ($currentCreditDate = $this->currentCreditDate($reading)) && ($reading->worked_dates[0] ?? null) !== $currentCreditDate)
                    <p class="text-sm text-gray-600">{{ __('messages.leave_descriptions.credit_comparison', ['before' => $currentCreditDate, 'after' => $reading->worked_dates[0]]) }}</p>
                @endif
                @if ($reading->category === 'hourly_settlement' && $reading->status === 'proposed' && ! $reason)
                    @php($preview = $this->hourlyPreview($reading))
                    <p class="text-sm text-gray-600">{{ __('messages.leave_descriptions.hourly_preview', ['minutes' => $preview['minutes'], 'found' => $preview['found'], 'from' => $reading->settled_from->toDateString(), 'to' => $reading->settled_to->toDateString()]) }}</p>
                @endif
                @if (in_array('described_work_date_no_credit', $request?->flags ?? [], true))<p class="text-sm text-amber-800">{{ __('messages.leave_descriptions.no_rest_credit', ['date' => implode(', ', $reading->worked_dates ?? [])]) }}</p>@endif
                @if (in_array('described_period_shortfall', $request?->flags ?? [], true))<p class="text-sm text-amber-800">{{ __('messages.leave_descriptions.period_shortfall') }}</p>@endif
                @if ($reading->error_code)<p class="mt-2 text-sm text-red-700">{{ $reading->error_code === 'ai_unavailable' ? __('messages.leave_descriptions.ai_unavailable') : __('messages.leave_descriptions.failed').' ('.$reading->error_code.')' }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($reading->error_code)<button type="button" wire:click="retry({{ $reading->id }})" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.retry') }}</button>@endif
                    @if ($reading->status === 'proposed' && $reading->category && ! $reading->error_code)@if (! $reason)<button type="button" wire:click="decide({{ $reading->id }}, 'confirmed')" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.confirm') }}</button>@endif<button type="button" wire:click="decide({{ $reading->id }}, 'rejected')" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.reject') }}</button>@endif
                    @if (in_array($reading->status, ['confirmed', 'rejected'], true))<button type="button" wire:click="decide({{ $reading->id }}, 'proposed')" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.undo') }}</button>@endif
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><p>{{ __('messages.list_empty') }}</p></x-ui.card>
        @endforelse
    </div>
    {{ $this->readings()->links() }}
</x-filament-panels::page>
