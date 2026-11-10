<x-filament-panels::page>
    @php $backFollowUp = ['from' => $from, 'to' => $to, 'state' => $state, 'issueType' => $issueType, 'branchId' => $branchId, 'managerId' => $managerId, 'page' => \Illuminate\Pagination\Paginator::resolveCurrentPage()]; @endphp
    <p class="text-sm text-gray-600">{{ __('messages.list_context.follow_up') }}</p>
    <p class="text-sm">{{ __('messages.leave_entry_ui.open_count', ['count' => $this->leaveEntryCount()]) }}</p>
    <div class="space-y-5">
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4" @if($state !== '' || $issueType !== '' || $branchId !== '' || $managerId !== '' || $from !== now('Asia/Baghdad')->startOfMonth()->toDateString() || $to !== now('Asia/Baghdad')->toDateString()) open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_filters') }}</summary>
        <section class="flex flex-wrap gap-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <label>{{ __('messages.follow_up_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border p-2"></label>
            <label>{{ __('messages.follow_up_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border p-2"></label>
            <label>{{ __('messages.follow_up_ui.state') }} <select wire:model.live="state" class="rounded-xl border p-2"><option value="">{{ __('messages.attendance_ui.all') }}</option><option value="open_cases">{{ __('messages.reporting_ui.tiles.open_cases') }}</option>@foreach (['open', 'waiting_manager', 'waiting_approver', 'waiting_hr', 'closed'] as $option)<option value="{{ $option }}">{{ __('messages.follow_up_ui.states.'.$option) }}</option>@endforeach</select></label>
            <label>{{ __('messages.follow_up_ui.issue') }} <select wire:model.live="issueType" class="rounded-xl border p-2"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach (['absence', 'late', 'early', 'exit', 'missing_punch', 'leave_entry'] as $option)<option value="{{ $option }}">{{ __('messages.follow_up_ui.items.'.$option) }}</option>@endforeach</select></label>
            <label>{{ __('messages.export_ui.branches') }} <select wire:model.live="branchId" class="rounded-xl border p-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($this->branches() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label>{{ __('messages.export_ui.managers') }} <input wire:model.live.debounce.300ms="managerSearch" class="rounded-xl border p-2" placeholder="{{ __('messages.follow_up_ui.employee_search') }}"><select wire:model.live="managerId" class="rounded-xl border p-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@if ($selected = $this->selectedManager())<option value="{{ $selected->id }}">{{ $selected->name }} · {{ $selected->employee_code }}</option>@endif @foreach ($this->managers() as $manager)<option value="{{ $manager->id }}">{{ $manager->name }} · {{ $manager->employee_code }}</option>@endforeach</select></label>
        </section></details>
        <button type="button" wire:click="export" class="rounded-xl border px-4 py-2">{{ __('messages.follow_up_ui.export') }}</button>
        @error('period') <p class="text-red-600">{{ $message }}</p> @enderror
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <x-ui.table>
                <thead><tr><th scope="col">{{ __('messages.reporting_ui.employee') }}</th><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.follow_up_ui.state') }}</th><th scope="col">{{ __('messages.follow_up_ui.issue') }}</th><th scope="col">{{ __('messages.follow_up_ui.routes_title') }}</th><th scope="col">{{ __('messages.follow_up_ui.close') }}</th></tr></thead>
                <tbody>
                @forelse ($this->cases() as $case)
                    <tr wire:key="follow-up-case-{{ $case->id }}">
                        <x-ui.td :label="__('messages.reporting_ui.employee')"><a class="ui-link" href="{{ route('employees.show', $case->employee) }}">{{ $case->employee?->name }}</a><span class="block text-xs"><bdi dir="ltr">{{ $case->employee?->employee_code }}</bdi></span></x-ui.td>
                        <x-ui.td :label="__('messages.office_ui.date')">@if ($case->case_kind === 'leave_entry')<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['request' => $case->leave_request_id, 'back_follow_up' => $backFollowUp]) }}">{{ $case->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</a>@else<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\DayDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['employee' => $case->employee_id, 'date' => $case->work_date->toDateString(), 'back_follow_up' => $backFollowUp]) }}">{{ $case->work_date->locale(app()->getLocale())->translatedFormat('l j F Y') }}</a>@endif</x-ui.td>
                        <x-ui.td :label="__('messages.follow_up_ui.state')"><x-ui.badge :tone="$case->state === 'closed' ? 'green' : 'red'">{{ __('messages.follow_up_ui.states.'.$case->state) }}</x-ui.badge></x-ui.td>
                        <x-ui.td :label="__('messages.follow_up_ui.issue')">@foreach ($case->items ?? [] as $item){{ __('messages.follow_up_ui.items.'.($item['type'] ?? 'absence')) }}@if ($item['minutes'] !== null) <bdi dir="auto">{{ $this->minutes($item['minutes']) }}</bdi>@endif · @endforeach</x-ui.td>
                        <x-ui.td :label="__('messages.follow_up_ui.routes_title')">@foreach ($case->routes ?? [] as $route){{ __('messages.follow_up_ui.routes.'.$route['kind']) }}@if ($route['external_id'] ?? null) <bdi dir="ltr">{{ $route['external_id'] }}</bdi>@endif @if ($reason = app(\App\Services\Attendance\FollowUp\FollowUpCases::class)->routeReason($route)){{ $reason }}@endif · @endforeach</x-ui.td>
                        <x-ui.td :label="__('messages.follow_up_ui.close')">@if ($case->state !== 'closed' && auth()->user()->can('sendAttendanceNotifications', $this->company()))
                            <input wire:model="closeReasons.{{ $case->id }}" class="ui-control max-w-full" placeholder="{{ __('messages.follow_up_ui.reason') }}"><button type="button" wire:click="closeCase({{ $case->id }})" class="ui-button ui-button-secondary ui-button-small">{{ __('messages.follow_up_ui.close') }}</button>
                            @error('closeReasons.'.$case->id) <p class="text-red-600">{{ $message }}</p> @enderror
                        @else — @endif</x-ui.td>
                    </tr>
                @empty <tr><td colspan="6" class="p-4"><x-ui.empty :title="__('messages.follow_up_ui.none')" /></td></tr> @endforelse
                </tbody>
            </x-ui.table>
            {{ $this->cases()->links() }}
        </section>
    </div>
</x-filament-panels::page>
