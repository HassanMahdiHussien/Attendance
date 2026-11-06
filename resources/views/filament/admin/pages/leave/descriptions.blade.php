<x-filament-panels::page>
    @php $counts = $this->counts(); @endphp
    <p class="text-sm text-gray-600">{{ __('messages.leave_descriptions.read_only_intro') }}</p>
    <x-ui.card>
        <p class="text-2xl font-semibold">{{ __('messages.leave_descriptions.note_count', ['count' => $counts['total']]) }}</p>
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('messages.leave_descriptions.show_by_category') }}</summary>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($this->categories() as $name)
                    <span class="rounded border border-line px-2 py-1">{{ __('messages.leave_descriptions.categories.'.$name) }}: {{ $counts['categories'][$name] ?? 0 }}</span>
                @endforeach
            </div>
        </details>
        @if ($counts['failures'] > 0)
            <p class="mt-3 text-sm text-red-700">{{ __('messages.leave_descriptions.failures', ['count' => $counts['failures']]) }}</p>
            <button type="button" wire:click="retryAll" class="ui-control mt-2 rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.retry_all', ['count' => $counts['failures']]) }}</button>
        @endif
    </x-ui.card>
    <x-ui.card>
        <div class="flex flex-wrap gap-3">
            <label>{{ __('messages.leave_descriptions.category') }} <select wire:model.live="category" class="ui-control rounded-lg border border-line p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->categories() as $name)<option value="{{ $name }}">{{ __('messages.leave_descriptions.categories.'.$name) }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_descriptions.branch') }} <select wire:model.live="branchId" class="ui-control rounded-lg border border-line p-2"><option value="">{{ __('messages.leave_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model.live="from" dir="ltr" class="ui-control rounded-lg border border-line p-2"></label>
            <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model.live="to" dir="ltr" class="ui-control rounded-lg border border-line p-2"></label>
        </div>
    </x-ui.card>
    <div class="space-y-4">
        @forelse ($this->readings() as $reading)
            @php
                $request = $reading->request;
                $description = $request?->currentVersion?->values['description'] ?? '';
            @endphp
            <x-ui.card>
                <p class="font-semibold">@if ($request?->employee)<a class="ui-link" href="{{ route('employees.show', $request->employee) }}">{{ $request->employee->name }}</a>@else{{ __('messages.leave_ui.unknown_employee') }}@endif · @if ($request)<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['request' => $request->id, 'back_descriptions' => ['category' => $category, 'branchId' => $branchId, 'from' => $from, 'to' => $to, 'page' => \Illuminate\Pagination\Paginator::resolveCurrentPage()]]) }}"><bdi dir="ltr">{{ $request->external_id }}</bdi></a>@endif</p>
                <p class="text-sm text-gray-600">{{ $request?->leaveType?->name }} · <bdi dir="ltr">{{ $request?->start_date?->toDateString() }} – {{ $request?->end_date?->toDateString() }}</bdi> · {{ $request?->duration_days }} {{ __('messages.leave_balance_ui.days') }}</p>
                <p class="mt-3 text-sm font-medium">{{ __('messages.leave_descriptions.employee_wrote') }}</p>
                <p class="whitespace-pre-wrap break-words" dir="auto">{{ $description }}</p>
                <p class="mt-3 text-sm font-medium">{{ __('messages.leave_descriptions.understood') }}</p>
                <p class="text-sm">
                    @if ($reading->category === 'compensation_for_day')
                        @if ($reading->worked_dates)
                            @php $dates = '<bdi dir="ltr">'.e(implode(', ', $reading->worked_dates)).'</bdi>'; @endphp
                            {!! __('messages.leave_descriptions.compensation_dates', ['dates' => $dates]) !!}
                        @else
                            {{ __('messages.leave_descriptions.compensation_no_dates') }}
                        @endif
                    @elseif ($reading->category === 'hourly_settlement')
                        @if ($reading->settled_from && $reading->settled_to)
                            @php
                                $fromDate = '<bdi dir="ltr">'.e($reading->settled_from->toDateString()).'</bdi>';
                                $toDate = '<bdi dir="ltr">'.e($reading->settled_to->toDateString()).'</bdi>';
                            @endphp
                            {!! __('messages.leave_descriptions.hourly_period', ['from' => $fromDate, 'to' => $toDate]) !!}
                        @else
                            {{ __('messages.leave_descriptions.hourly_no_period') }}
                        @endif
                    @elseif ($reading->category)
                        {{ __('messages.leave_descriptions.categories.'.$reading->category) }}
                    @else
                        {{ __('messages.leave_descriptions.waiting') }}
                    @endif
                    @if ($reading->hours !== null){{ __('messages.leave_descriptions.hours_suffix', ['hours' => (float) $reading->hours]) }}@endif
                </p>
                @if ($reading->confidence === 'low')<p class="mt-1 text-xs text-gray-500">{{ __('messages.leave_descriptions.low_confidence') }}</p>@endif
                @if (in_array('described_work_date_no_credit', $request?->flags ?? [], true))<p class="mt-2 text-sm text-amber-800">{{ __('messages.leave_descriptions.no_rest_credit', ['date' => implode(', ', $reading->worked_dates ?? [])]) }}</p>@endif
                @if (in_array('described_period_shortfall', $request?->flags ?? [], true))<p class="mt-2 text-sm text-amber-800">{{ __('messages.leave_descriptions.period_shortfall') }}</p>@endif
                @if ($reading->error_code)<p class="mt-2 text-sm text-red-700">{{ __('messages.leave_descriptions.errors.'.(in_array($reading->error_code, ['ai_unavailable', 'read_failed'], true) ? $reading->error_code : 'other')) }}</p>@endif
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($reading->error_code)<button type="button" wire:click="retry({{ $reading->id }})" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.retry') }}</button>@endif
                    @if (in_array($reading->status, ['confirmed', 'rejected'], true))
                        <span class="text-xs text-gray-500">{{ __('messages.leave_descriptions.statuses.'.$reading->status) }}</span>
                        <button type="button" wire:click="undo({{ $reading->id }})" class="ui-control rounded-lg border border-line px-3 py-2">{{ __('messages.leave_descriptions.undo') }}</button>
                    @endif
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><p>{{ __('messages.list_empty') }}</p></x-ui.card>
        @endforelse
    </div>
    {{ $this->readings()->links() }}
</x-filament-panels::page>
