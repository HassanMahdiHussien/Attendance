<x-filament-panels::page>
    <div class="space-y-5">
        <section class="flex flex-wrap gap-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <label>{{ __('messages.follow_up_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border p-2"></label>
            <label>{{ __('messages.follow_up_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border p-2"></label>
            <label>{{ __('messages.follow_up_ui.state') }} <select wire:model.live="state" class="rounded-xl border p-2"><option value="">{{ __('messages.attendance_ui.all') }}</option><option value="open_cases">{{ __('messages.reporting_ui.tiles.open_cases') }}</option>@foreach (['open', 'waiting_manager', 'waiting_approver', 'waiting_hr', 'closed'] as $option)<option value="{{ $option }}">{{ __('messages.follow_up_ui.states.'.$option) }}</option>@endforeach</select></label>
            <label>{{ __('messages.follow_up_ui.issue') }} <select wire:model.live="issueType" class="rounded-xl border p-2"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach (['absence', 'late', 'early', 'exit', 'missing_punch'] as $option)<option value="{{ $option }}">{{ __('messages.follow_up_ui.items.'.$option) }}</option>@endforeach</select></label>
            <label>{{ __('messages.export_ui.branches') }} <select wire:model.live="branchId" class="rounded-xl border p-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($this->branches() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label>{{ __('messages.export_ui.managers') }} <input wire:model.live.debounce.300ms="managerSearch" class="rounded-xl border p-2" placeholder="{{ __('messages.follow_up_ui.employee_search') }}"><select wire:model.live="managerId" class="rounded-xl border p-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@if ($selected = $this->selectedManager())<option value="{{ $selected->id }}">{{ $selected->name }} · {{ $selected->employee_code }}</option>@endif @foreach ($this->managers() as $manager)<option value="{{ $manager->id }}">{{ $manager->name }} · {{ $manager->employee_code }}</option>@endforeach</select></label>
        </section>
        <button type="button" wire:click="export" class="rounded-xl border px-4 py-2">{{ __('messages.follow_up_ui.export') }}</button>
        @error('period') <p class="text-red-600">{{ $message }}</p> @enderror
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            @forelse ($this->cases() as $case)
                <article wire:key="follow-up-case-{{ $case->id }}" class="border-b pb-3">
                    <div class="flex flex-wrap gap-3"><a class="underline" href="{{ \App\Filament\Admin\Pages\Attendance\DayDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['employee' => $case->employee_id, 'date' => $case->work_date->toDateString()]) }}">{{ $case->employee?->name }} · <bdi dir="ltr">{{ $case->employee?->employee_code }}</bdi> · <bdi dir="ltr">{{ $case->work_date->toDateString() }}</bdi></a><span>{{ __('messages.follow_up_ui.states.'.$case->state) }}</span></div>
                    <a class="ui-link" href="{{ route('employees.show', $case->employee) }}">{{ __('messages.reporting_ui.employee') }}</a>
                    <p>@foreach ($case->items ?? [] as $item){{ __('messages.follow_up_ui.items.'.($item['type'] ?? 'absence')) }}@if ($item['minutes'] !== null) <bdi dir="ltr">{{ $this->minutes($item['minutes']) }}</bdi>@endif · @endforeach</p>
                    <p>@foreach ($case->routes ?? [] as $route){{ __('messages.follow_up_ui.routes.'.$route['kind']) }}@if ($route['external_id'] ?? null) <bdi dir="ltr">{{ $route['external_id'] }}</bdi>@endif @if ($reason = app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->routeReason($route)){{ $reason }}@endif · @endforeach</p>
                    @if ($case->state !== 'closed' && auth()->user()->can('sendAttendanceNotifications', $this->company()))
                        <input wire:model="closeReason" class="rounded-xl border p-2" placeholder="{{ __('messages.follow_up_ui.reason') }}"><button type="button" wire:click="closeCase({{ $case->id }})" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.close') }}</button>
                        @error('closeReason') <p class="text-red-600">{{ $message }}</p> @enderror
                    @endif
                </article>
            @empty <p>{{ __('messages.follow_up_ui.none') }}</p> @endforelse
            {{ $this->cases()->links() }}
        </section>
    </div>
</x-filament-panels::page>
