<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><x-ui.page-header :title="__('messages.reporting_ui.dashboard')" />
        @if($uploadItem = (\App\Support\Access\AdminNav::uploadItems(auth()->user())[0] ?? null))<a href="{{ $uploadItem['url'] }}" class="rounded-lg border border-line bg-white px-4 py-2 text-sm font-medium text-ink">{{ __('messages.upload_center.dashboard_button') }}</a>@endif
    </div>
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
            <div class="relative text-sm">
                <label for="report-employee-search">{{ __('messages.reporting_ui.employee') }}</label>
                <div class="flex gap-2">
                    <input id="report-employee-search" type="search" wire:model.live.debounce.250ms="employeeSearch" role="combobox" aria-autocomplete="list" aria-controls="report-employee-results" aria-expanded="{{ $employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" />
                    @if ($employeeSearch !== '') <button type="button" wire:click="clearEmployee" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_employee') }}">{{ __('messages.reporting_ui.all') }}</button> @endif
                </div>
                @if ($employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2)
                    <div id="report-employee-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                        @forelse ($employees as $employee)
                            <button type="button" role="option" aria-selected="false" wire:click="chooseEmployee({{ $employee->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light focus:bg-primary-light"><strong class="block">{{ $employee->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch_name }}</span></button>
                        @empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse
                    </div>
                @endif
            </div>
            <label class="text-sm">{{ __('messages.reporting_ui.branch') }} <select wire:model="branchId" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.department') }} <select wire:model="department" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach ($departments as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
            <div class="relative text-sm">
                <label for="report-manager-search">{{ __('messages.reporting_ui.manager') }}</label>
                <div class="flex gap-2">
                    <input id="report-manager-search" type="search" wire:model.live.debounce.250ms="managerSearch" role="combobox" aria-autocomplete="list" aria-controls="report-manager-results" aria-expanded="{{ $managerId === '' && mb_strlen(trim($managerSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" />
                    @if ($managerSearch !== '') <button type="button" wire:click="clearManager" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_manager') }}">{{ __('messages.reporting_ui.all') }}</button> @endif
                </div>
                @if ($managerId === '' && mb_strlen(trim($managerSearch)) >= 2)
                    <div id="report-manager-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
                        @forelse ($managers as $manager)
                            <button type="button" role="option" aria-selected="false" wire:click="chooseManager({{ $manager->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light focus:bg-primary-light"><strong class="block">{{ $manager->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $manager->employee_code }}</bdi> · {{ $manager->branch_name }}</span></button>
                        @empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse
                    </div>
                @endif
            </div>
            <label class="text-sm">{{ __('messages.reporting_ui.work_pattern') }} <select wire:model="workPattern" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (['office', 'shift', 'security'] as $pattern)<option value="{{ $pattern }}">{{ __('messages.reporting_ui.patterns.'.$pattern) }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.status') }} <select wire:model="status" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (\App\Services\Attendance\Reporting\ReportFilters::STATUSES as $choice)<option value="{{ $choice }}">{{ __('messages.office_ui.statuses.'.$choice) }}</option>@endforeach</select></label>
            <button type="submit" class="ui-button ui-button-primary">{{ __('messages.reporting_ui.apply') }}</button>
        </form>
        @error('period') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-bold text-ink">{{ __('messages.export_ui.export') }}</h2>
        <form wire:submit="requestExport" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-sm">{{ __('messages.export_ui.report') }}
                <select wire:model="exportKind" class="ui-control block rounded-xl border border-gray-200 px-3 py-2">
                    @foreach (['lateness_reconciliation', 'absence', 'missing_punches', 'leave_and_credit', 'restaurant_usage', 'data_issues', 'overtime', 'compensatory_days'] as $kind)
                        <option value="{{ $kind }}">{{ __('messages.export_ui.kinds.'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('messages.export_ui.language') }}
                <select wire:model="exportLocale" class="ui-control block rounded-xl border border-gray-200 px-3 py-2">
                    <option value="en">{{ __('messages.export_ui.english') }}</option><option value="ar">{{ __('messages.export_ui.arabic') }}</option>
                </select>
            </label>
            <button type="submit" class="ui-button ui-button-secondary">{{ __('messages.export_ui.create') }}</button>
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceExportsPage::getUrl(panel: 'admin', tenant: $company) }}">{{ __('messages.export_ui.exports') }}</a>
        </form>
        @if ($exportQueued) <p class="mt-2 text-sm text-green-800">{{ __('messages.export_ui.queued_notice') }}</p> @endif
        @error('export') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($tiles as $key => $value)
            <a href="{{ $this->tileUrl($key) }}" class="rounded-2xl border border-gray-200 bg-white p-3 sm:p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">
                <span class="block text-sm text-gray-500">{{ \App\Services\Attendance\Reporting\ReportTiles::label($key) }}</span>
                <strong class="mt-1 block font-mono text-xl text-ink"><bdi dir="ltr">{{ \App\Services\Attendance\Reporting\ReportTiles::isMinutes($key) ? sprintf('%d:%02d', intdiv($value, 60), $value % 60) : (\App\Services\Attendance\Reporting\ReportTiles::isDays($key) ? number_format($value, 1) : \App\Support\Format\Ui::number($value)) }}</bdi></strong>
                @if ($key === 'holiday_worked')
                    <span class="block text-sm text-gray-600">{{ __('messages.holiday_ui.hours') }} <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv($totals['holiday_work_minutes'], 60), $totals['holiday_work_minutes'] % 60) }}</bdi></span>
                @endif
            </a>
        @endforeach
    </div>

    @if ($totals['issues']['company_wide'] || $totals['meals']['non_employee'])
        <x-ui.card>
            <h2 class="font-bold text-ink">{{ __('messages.reporting_ui.company_wide') }}</h2>
            @foreach ($totals['issues']['company_wide'] as $type => $count)
                <p class="mt-2 text-sm">{{ app()->getLocale() === 'en' ? ucfirst(__('messages.attendance_ui.issue_types.'.$type)) : __('messages.attendance_ui.issue_types.'.$type) }}: <bdi dir="ltr">{{ $count }}</bdi></p>
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
