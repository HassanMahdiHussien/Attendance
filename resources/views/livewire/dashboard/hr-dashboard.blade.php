<div class="space-y-6">
    <x-ui.page-header :title="__('messages.reporting_ui.dashboard')" />
    <x-ui.card>
        <h2 class="text-lg font-bold text-ink">{{ $company->name }}</h2>
        <p class="text-sm text-gray-500"><bdi dir="ltr">{{ $from }} → {{ $to }}</bdi></p>
        <div class="mt-3 flex flex-wrap gap-3 text-sm">
            <button type="button" wire:click="period('current')" class="ui-link">{{ __('messages.reporting_ui.current_month') }}</button>
            <button type="button" wire:click="period('previous')" class="ui-link">{{ __('messages.reporting_ui.previous_month') }}</button>
            <button type="button" wire:click="period('last_30')" class="ui-link">{{ __('messages.reporting_ui.last_30') }}</button>
        </div>
        <form wire:submit="apply" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-sm">{{ __('messages.reporting_ui.from') }} <input type="date" wire:model="from" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" /></label>
            <label class="text-sm">{{ __('messages.reporting_ui.to') }} <input type="date" wire:model="to" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" /></label>
            <label class="text-sm">{{ __('messages.reporting_ui.employee') }} <select wire:model="employeeId" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.branch') }} <select wire:model="branchId" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.department') }} <select wire:model="department" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($departments as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.manager') }} <select wire:model="managerId" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.work_pattern') }} <select wire:model="workPattern" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (['office', 'shift', 'security'] as $pattern)<option value="{{ $pattern }}">{{ __('messages.reporting_ui.patterns.'.$pattern) }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.status') }} <select wire:model="status" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (\App\Services\Attendance\Reporting\ReportFilters::STATUSES as $choice)<option value="{{ $choice }}">{{ __('messages.office_ui.statuses.'.$choice) }}</option>@endforeach</select></label>
            <button type="submit" class="ui-link text-start">{{ __('messages.reporting_ui.apply') }}</button>
        </form>
        @error('period') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tiles as $key => $value)
            <a href="{{ $this->tileUrl($key) }}" class="rounded-2xl border border-gray-200 bg-white p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">
                <span class="block text-sm text-gray-500">{{ str_starts_with($key, 'leave:') ? substr($key, 6, strrpos($key, '|') - 6).' · '.__('messages.leave_ui.statuses.'.substr($key, strrpos($key, '|') + 1)) : (str_starts_with($key, 'issue_type:') ? __('messages.attendance_ui.issue_types.'.substr($key, 11)) : __('messages.reporting_ui.tiles.'.$key)) }}</span>
                <strong class="mt-1 block font-mono text-xl text-ink" dir="ltr">{{ \App\Services\Attendance\Reporting\ReportTiles::isMinutes($key) ? sprintf('%d:%02d', intdiv($value, 60), $value % 60) : \App\Support\Format\Ui::number($value) }}</strong>
            </a>
        @endforeach
    </div>

    @if ($totals['issues']['company_wide'] || $totals['meals']['non_employee'])
        <x-ui.card>
            <h2 class="font-bold text-ink">{{ __('messages.reporting_ui.company_wide') }}</h2>
            @foreach ($totals['issues']['company_wide'] as $type => $count)
                <p class="mt-2 text-sm">{{ __('messages.attendance_ui.issue_types.'.$type) }}: <bdi dir="ltr">{{ $count }}</bdi></p>
            @endforeach
            <p class="mt-2 text-sm">{{ __('messages.reporting_ui.non_employee_meals') }}: <bdi dir="ltr">{{ $totals['meals']['non_employee'] }}</bdi></p>
            @foreach ($totals['meals']['non_employee_by_department'] as $department => $count)
                <p class="ms-3 text-sm">{{ $department }}: <bdi dir="ltr">{{ $count }}</bdi></p>
            @endforeach
        </x-ui.card>
    @endif

    <x-ui.card>
        <h2 class="font-bold text-ink">{{ __('messages.reporting_ui.freshness') }}</h2>
        @if ($freshness['results_updating']) <p class="mt-2 text-sm text-amber-800">{{ __('messages.reporting_ui.updating') }}</p> @endif
        <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($freshness['sources'] as $source => $time)
                <p>{{ __('messages.reporting_ui.sources.'.$source) }}: <bdi dir="ltr">{{ $time ?? '—' }}</bdi></p>
            @endforeach
            @foreach ($freshness['runs'] as $run => $time)
                <p>{{ __('messages.reporting_ui.runs.'.$run) }}: <bdi dir="ltr">{{ $time ?? '—' }}</bdi></p>
            @endforeach
        </div>
    </x-ui.card>
    <x-ui.card>
        <h2 class="font-bold text-ink">{{ __('messages.reporting_ui.coverage') }}</h2>
        @foreach ($branches as $branch)
            <p class="mt-2 text-sm">{{ $branch->name }}: <bdi dir="ltr">{{ $freshness['coverage'][$branch->id]['assessable'] ?? 0 }} / {{ $freshness['coverage'][$branch->id]['expected'] ?? 0 }}</bdi> {{ __('messages.reporting_ui.covered_days') }}</p>
        @endforeach
    </x-ui.card>
</div>
