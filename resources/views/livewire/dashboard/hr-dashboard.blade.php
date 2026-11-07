<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><x-ui.page-header :title="__('messages.reporting_ui.dashboard')" />
        @if($uploadItem = (\App\Support\Access\AdminNav::uploadItems(auth()->user())[0] ?? null))<a href="{{ $uploadItem['url'] }}" class="rounded-lg border border-line bg-white px-4 py-2 text-sm font-medium text-ink">{{ __('messages.upload_center.dashboard_button') }}</a>@endif
    </div>
    <p class="text-sm text-gray-600">{{ $company->name }} · <bdi dir="ltr" class="whitespace-nowrap">{{ $from }} → {{ $to }}</bdi> · {{ __('messages.reporting_ui.data_up_to', ['date' => $freshness['last_data_update'] ? \Carbon\CarbonImmutable::parse($freshness['last_data_update'])->timezone('Asia/Baghdad')->translatedFormat('j M') : '—']) }} · {{ $freshness['results_updating'] ? __('messages.reporting_ui.updating') : ($tasks === [] ? __('messages.reporting_ui.nothing_needed') : trans_choice('messages.reporting_ui.things_needed', count($tasks), ['count' => count($tasks)])) }}</p>
    <div class="flex flex-wrap gap-2 text-sm">
            <x-ui.filter-button wire:click="period('current')" :selected="$from === now('Asia/Baghdad')->startOfMonth()->toDateString()">{{ __('messages.reporting_ui.current_month') }}</x-ui.filter-button>
            <x-ui.filter-button wire:click="period('previous')" :selected="$from === now('Asia/Baghdad')->subMonthNoOverflow()->startOfMonth()->toDateString()">{{ __('messages.reporting_ui.previous_month') }}</x-ui.filter-button>
            <x-ui.filter-button wire:click="period('last_30')" :selected="$from === now('Asia/Baghdad')->subDays(29)->toDateString()">{{ __('messages.reporting_ui.last_30') }}</x-ui.filter-button>
    </div>
    @error('period') <p class="text-red-700">{{ $message }}</p> @enderror
    <x-ui.card>
        <h2 class="text-lg font-semibold text-ink">{{ __('messages.reporting_ui.needed_from_you') }}</h2>
        @forelse ($tasks as $task)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-4 last:border-0">
                <div class="min-w-0 flex-1"><p class="font-semibold text-ink"><span class="font-mono tabular-nums">{{ \App\Support\Format\Ui::number($task['count']) }}</span> {{ trans_choice('messages.reporting_ui.task_counts.'.$task['key'], $task['count']) }}</p><p class="text-sm text-gray-600">{{ __('messages.reporting_ui.task_help.'.$task['key']) }}</p></div>
                <a href="{{ $this->tileUrl($task['key']) }}" class="ui-button {{ $loop->first ? 'ui-button-primary' : 'ui-button-secondary' }}">{{ __('messages.reporting_ui.task_actions.'.$task['key']) }}</a>
            </div>
        @empty
            <p class="mt-3 text-sm text-gray-600">{{ __('messages.reporting_ui.nothing_needed') }}</p>
        @endforelse
    </x-ui.card>
    <section><h2 class="mb-3 text-lg font-semibold text-ink">{{ __('messages.reporting_ui.month_figures') }}</h2><div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($figures as $figure)
            <a href="{{ $this->tileUrl($figure['key'] === 'attendance_rate' ? 'present' : $figure['key']) }}" class="min-w-0 rounded-xl border border-line bg-white p-4 text-ink">
                <span class="block text-sm">{{ __('messages.reporting_ui.figure_labels.'.$figure['key']) }}</span>
                <strong class="mt-2 block whitespace-nowrap font-mono text-lg tabular-nums sm:text-2xl">{{ $figure['value'] === null ? '—' : ($figure['key'] === 'attendance_rate' ? \App\Support\Format\Ui::number($figure['value']).'%' : app(\App\Services\Attendance\Reporting\DurationText::class)->format($figure['value'])) }}</strong>
                <span class="mt-2 block text-xs {{ $figure['tone'] === 'better' ? 'text-green-700' : ($figure['tone'] === 'worse' ? 'text-red-700' : 'text-gray-500') }}">{{ $figure['difference'] === null ? __('messages.reporting_ui.no_comparison') : ($figure['difference'] === 0 ? __('messages.reporting_ui.same_period') : __('messages.reporting_ui.change_period', ['change' => ($figure['difference'] > 0 ? '+' : '−').($figure['key'] === 'attendance_rate' ? abs($figure['difference']).' '.__('messages.reporting_ui.points') : app(\App\Services\Attendance\Reporting\DurationText::class)->format(abs($figure['difference'])))]) ) }}</span>
            </a>
        @endforeach
    </div></section>
    <div class="grid gap-3 lg:grid-cols-2">
        @if ($topLate !== [])<x-ui.card><h2 class="font-semibold text-ink">{{ __('messages.reporting_ui.most_late') }}</h2>
            @foreach ($topLate as $employee)<a href="{{ route('employees.show', $employee->employee_id) }}" class="flex justify-between gap-3 border-b border-line py-3 text-sm last:border-0"><span class="min-w-0 text-ink">{{ $employee->name }} <bdi dir="ltr" class="text-gray-500">{{ $employee->employee_code }}</bdi></span><bdi dir="auto" class="shrink-0 font-mono">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format((int) $employee->minutes) }}</bdi></a>@endforeach
        </x-ui.card>@endif
        <x-ui.card><h2 class="font-semibold text-ink">{{ __('messages.reporting_ui.latest_files') }}</h2>
            @foreach ($latestFiles as $file)<div class="border-b border-line py-3 text-sm last:border-0"><p>{{ __('messages.reporting_ui.sources.'.$file['source']) }}: <bdi dir="ltr" class="font-mono">{{ $file['time'] ?? '—' }}</bdi></p>@if ($file['old'] && $uploadItem)<a href="{{ $uploadItem['url'] }}" class="text-amber-800">{{ __('messages.reporting_ui.upload_newer') }}</a>@endif</div>@endforeach
        </x-ui.card>
    </div>
    <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_figures') }}</summary><div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($tiles as $key => $value)
            @unless (in_array($key, array_merge(array_column($tasks, 'key'), ['late', 'early', 'remaining']), true))
                <x-ui.tile :label="\App\Services\Attendance\Reporting\ReportTiles::label($key)" :value="\App\Services\Attendance\Reporting\ReportTiles::isMinutes($key) ? app(\App\Services\Attendance\Reporting\DurationText::class)->format($value) : (\App\Services\Attendance\Reporting\ReportTiles::isDays($key) ? number_format($value, 1) : \App\Support\Format\Ui::number($value))" :href="$this->tileUrl($key)">
                    @if ($key === 'holiday_worked')<span class="block text-sm text-gray-600">{{ __('messages.holiday_ui.hours') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($totals['holiday_work_minutes']) }}</bdi></span>@endif
                </x-ui.tile>
            @endunless
        @endforeach
    </div></details>
        <details wire:ignore.self class="mt-4" @if ($employeeId !== '' || $branchId !== '' || $department !== '' || $managerId !== '' || $workPattern !== '' || $shift !== '' || $status !== '') open @endif><summary class="cursor-pointer font-semibold text-primary">{{ __('messages.clear_screens.more_filters') }}</summary>
        <form wire:submit="apply" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="relative text-sm"><label for="report-employee-search">{{ __('messages.reporting_ui.employee') }}</label><div class="flex gap-2"><input id="report-employee-search" type="search" wire:model.live.debounce.250ms="employeeSearch" role="combobox" aria-autocomplete="list" aria-controls="report-employee-results" aria-expanded="{{ $employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2 ? 'true' : 'false' }}" placeholder="{{ __('messages.search.placeholder') }}" class="ui-control block w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2" />@if ($employeeSearch !== '') <button type="button" wire:click="clearEmployee" class="ui-link" aria-label="{{ __('messages.reporting_ui.clear_employee') }}">{{ __('messages.reporting_ui.all') }}</button> @endif</div>
                @if ($employeeId === '' && mb_strlen(trim($employeeSearch)) >= 2)<div id="report-employee-results" role="listbox" class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-line bg-white shadow-xl">@forelse ($employees as $employee)<button type="button" role="option" aria-selected="false" wire:click="chooseEmployee({{ $employee->id }})" class="block w-full px-3 py-2 text-start hover:bg-primary-light"><strong class="block">{{ $employee->name }}</strong><span class="text-xs text-gray-500"><bdi dir="ltr">{{ $employee->employee_code }}</bdi> · {{ $employee->branch_name }}</span></button>@empty <p class="p-3 text-gray-500">{{ __('messages.search.no_results') }}</p> @endforelse</div>@endif
            </div>
            <label class="text-sm">{{ __('messages.reporting_ui.from') }} <input type="date" wire:model="from" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" /></label>
            <label class="text-sm">{{ __('messages.reporting_ui.to') }} <input type="date" wire:model="to" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2" /></label>
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
            <label class="text-sm">{{ __('messages.shifts.label') }} <select wire:model="shift" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (\App\Services\Attendance\ShiftDisplay::KEYS as $key)<option value="{{ $key }}">{{ __('messages.shifts.names.'.$key) }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('messages.reporting_ui.status') }} <select wire:model="status" class="ui-control block w-full rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.reporting_ui.all') }}</option>@foreach (\App\Services\Attendance\Reporting\ReportFilters::STATUSES as $choice)<option value="{{ $choice }}">{{ __('messages.office_ui.statuses.'.$choice) }}</option>@endforeach</select></label>
            <button type="submit" class="ui-button ui-button-secondary">{{ __('messages.reporting_ui.apply') }}</button>
        </form>
        </details>
    <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.export_ui.export') }}</summary>
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
    </details>

    <details id="data-status" wire:ignore.self class="rounded-xl border border-line bg-white p-4" @if ($freshness['results_updating']) open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.data_status') }}</summary>

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
    </details>
</div>
