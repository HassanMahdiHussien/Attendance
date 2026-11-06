<div class="space-y-4">
    <x-ui.card :title="__('messages.follow_up_ui.cases')">
        <x-ui.table>
            <thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.follow_up_ui.state') }}</th><th scope="col">{{ __('messages.follow_up_ui.issue') }}</th><th scope="col">{{ __('messages.follow_up_ui.routes_title') }}</th></tr></thead>
            <tbody>
            @forelse ($cases as $case)
                <tr wire:key="employee-case-{{ $case->id }}">
                    <x-ui.td :label="__('messages.office_ui.date')">{{ $case->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</x-ui.td>
                    <x-ui.td :label="__('messages.follow_up_ui.state')"><x-ui.badge :tone="$case->state === 'closed' ? 'green' : 'red'">{{ __('messages.follow_up_ui.states.'.$case->state) }}</x-ui.badge></x-ui.td>
                    <x-ui.td :label="__('messages.follow_up_ui.issue')">@foreach ($case->items ?? [] as $item){{ __('messages.follow_up_ui.items.'.$item['type']) }}@if ($item['minutes'] !== null) <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($item['minutes']) }}</bdi>@endif · @endforeach</x-ui.td>
                    <x-ui.td :label="__('messages.follow_up_ui.routes_title')">
                        @foreach ($case->routes ?? [] as $route){{ __('messages.follow_up_ui.routes.'.$route['kind']) }}@if ($route['external_id'] ?? null) <bdi dir="ltr">{{ $route['external_id'] }}</bdi>@endif @if ($routeReason = app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->routeReason($route)){{ $routeReason }}@endif · @endforeach
                        @foreach ($case->deliveryRoutes as $delivery)<p>{{ __('messages.follow_up_ui.last_sent') }}: <bdi dir="ltr">{{ $delivery->last_sent_at ?? '—' }}</bdi> · {{ __('messages.follow_up_ui.reminders_sent') }}: <bdi dir="ltr">{{ max(0, $delivery->sent_count - 1) }}</bdi></p>@endforeach
                        @if ($case->closure_source) <p>{{ __('messages.follow_up_ui.closure_source') }}: {{ __('messages.follow_up_ui.closure_kinds.'.($case->closure_source['kind'] ?? 'reassessment')) }} @foreach (app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->closureRequests($case) as $request)<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $employee->company).'?'.http_build_query(['request' => $request->id]) }}"><bdi dir="ltr">{{ $request->external_id }}</bdi></a> @endforeach</p> @endif
                        @if (($case->closure_source['kind'] ?? null) === 'manual' && $case->closure_reason) <p>{{ __('messages.follow_up_ui.reason') }}: {{ $case->closure_reason }}</p> @endif
                        <details><summary>{{ __('messages.follow_up_ui.event_history') }}</summary>@foreach ($case->events as $event)<p><bdi dir="ltr">{{ $event->created_at }}</bdi> · {{ __('messages.follow_up_ui.events.'.$event->event) }}</p>@endforeach</details>
                        @include('attendance.partials.follow-up-close', ['case' => $case, 'company' => $employee->company, 'model' => 'closeReasons.'.$case->id, 'emailUrl' => '#follow-up-email'])
                    </x-ui.td>
                </tr>
            @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('messages.follow_up_ui.none')" /></td></tr> @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
    @can('update', $employee)
        <x-ui.card id="follow-up-email" :title="__('messages.follow_up_ui.save_email')">
            <div class="flex flex-wrap gap-3">
                <label>{{ __('messages.follow_up_ui.email') }} <input type="email" wire:model="email" class="rounded-xl border p-2" dir="ltr"></label>
                <label>{{ __('messages.follow_up_ui.fallback_email') }} <input type="email" wire:model="fallbackEmail" class="rounded-xl border p-2" dir="ltr"></label>
                <label>{{ __('messages.follow_up_ui.effective_date') }} <input type="date" wire:model="effectiveDate" class="rounded-xl border p-2" dir="ltr"></label>
                <label>{{ __('messages.follow_up_ui.reason') }} <input wire:model="reason" class="rounded-xl border p-2"></label>
                <button type="button" wire:click="save" class="rounded-xl border px-4 py-2">{{ __('messages.follow_up_ui.save_email') }}</button>
            </div>
            @error('email') <p class="text-red-600">{{ $message }}</p> @enderror
            @error('fallbackEmail') <p class="text-red-600">{{ $message }}</p> @enderror
            @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
        </x-ui.card>
    @endcan
</div>
