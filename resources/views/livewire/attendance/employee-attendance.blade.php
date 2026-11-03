<div class="space-y-5">
    <x-ui.card>
        @php $monthPeriod = \Carbon\CarbonImmutable::parse($from)->format('Y-m') === \Carbon\CarbonImmutable::parse($to)->format('Y-m'); @endphp
        <h2 class="text-lg font-bold text-ink">{{ __('messages.clear_screens.daily_record', ['period' => $monthPeriod ? \Carbon\CarbonImmutable::parse($from)->locale(app()->getLocale())->translatedFormat('F Y') : $from.' – '.$to]) }}</h2>
        <p class="text-sm text-gray-500">{{ $employee->branch?->name ?? '—' }} · {{ $employee->work_pattern ? __('messages.reporting_ui.patterns.'.$employee->work_pattern->value) : __('messages.office_ui.statuses.pattern_missing') }} · {{ $freshness['results_updating'] ? __('messages.reporting_ui.updating') : __('messages.clear_screens.results_ready') }}</p>
        <p class="text-sm text-gray-500">{{ __('messages.reporting_ui.freshness') }}: <bdi dir="ltr">{{ $freshness['last_data_update'] ?? '—' }}</bdi></p>
        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.filter-button wire:click="period('current')" :selected="$from === now('Asia/Baghdad')->startOfMonth()->toDateString()">{{ __('messages.reporting_ui.current_month') }}</x-ui.filter-button>
            <x-ui.filter-button wire:click="period('previous')" :selected="$from === now('Asia/Baghdad')->subMonthNoOverflow()->startOfMonth()->toDateString()">{{ __('messages.reporting_ui.previous_month') }}</x-ui.filter-button>
            <x-ui.filter-button wire:click="period('last_30')" :selected="$from === now('Asia/Baghdad')->subDays(29)->toDateString()">{{ __('messages.reporting_ui.last_30') }}</x-ui.filter-button>
        </div>
        <details wire:ignore.self class="mt-3"><summary class="cursor-pointer text-sm text-primary">{{ __('messages.clear_screens.custom_dates') }}</summary>
        <form wire:submit="apply" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-sm">{{ __('messages.reporting_ui.from') }} <input type="date" wire:model="from" class="ui-control block rounded-xl border border-gray-200 px-3 py-2" /></label>
            <label class="text-sm">{{ __('messages.reporting_ui.to') }} <input type="date" wire:model="to" class="ui-control block rounded-xl border border-gray-200 px-3 py-2" /></label>
            <button type="submit" class="ui-button ui-button-primary">{{ __('messages.reporting_ui.apply') }}</button>
        </form>
        </details>
        @error('period') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.export_ui.export') }}</summary>
        <form wire:submit="requestExport" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-sm">{{ __('messages.export_ui.report') }}
                <select wire:model="exportKind" class="ui-control block rounded-xl border border-gray-200 px-3 py-2">
                    <option value="employee_statement_xlsx">{{ __('messages.export_ui.kinds.employee_statement_xlsx') }}</option>
                    <option value="statement_pdf">{{ __('messages.export_ui.kinds.statement_pdf') }}</option>
                </select>
            </label>
            <label class="text-sm">{{ __('messages.export_ui.language') }}
                <select wire:model="exportLocale" class="ui-control block rounded-xl border border-gray-200 px-3 py-2">
                    <option value="en">{{ __('messages.export_ui.english') }}</option><option value="ar">{{ __('messages.export_ui.arabic') }}</option>
                </select>
            </label>
            <button type="submit" class="ui-button ui-button-secondary">{{ __('messages.export_ui.create') }}</button>
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceExportsPage::getUrl(panel: 'admin', tenant: $employee->company) }}">{{ __('messages.export_ui.exports') }}</a>
        </form>
        @if ($exportQueued) <p class="mt-2 text-sm text-green-800">{{ __('messages.export_ui.queued_notice') }}</p> @endif
        @error('export') <p class="text-red-700">{{ $message }}</p> @enderror
    </details>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.tile :label="__('messages.clear_screens.days_present')" :value="\App\Support\Format\Ui::number($tiles['present'])" wire:click="showTile('present')" />
        <x-ui.tile :label="__('messages.clear_screens.absent_without_leave')" :value="\App\Support\Format\Ui::number(max(0, $tiles['absent'] - $tiles['absence_closed_by_request']))" :tone="$tiles['absent'] > $tiles['absence_closed_by_request'] ? 'red' : 'white'" wire:click="showTile('absent_uncovered')" />
        <x-ui.tile :label="__('messages.clear_screens.late_early')" :value="app(\App\Services\Attendance\Reporting\DurationText::class)->format($tiles['late'] + $tiles['early'])" :tone="$tiles['late'] + $tiles['early'] > 0 ? 'amber' : 'white'" wire:click="showTile('late_early')" />
        <x-ui.tile :label="__('messages.clear_screens.not_covered')" :value="app(\App\Services\Attendance\Reporting\DurationText::class)->format($tiles['remaining'])" wire:click="showTile('remaining')" />
    </div>
    <details wire:ignore.self class="rounded-xl border border-line bg-white p-4"><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_figures') }}</summary>
        <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($tiles as $key => $value)
            @unless (in_array($key, ['present', 'remaining'], true))
                <x-ui.tile :label="\App\Services\Attendance\Reporting\ReportTiles::label($key)" :value="\App\Services\Attendance\Reporting\ReportTiles::isMinutes($key) ? app(\App\Services\Attendance\Reporting\DurationText::class)->format($value) : (\App\Services\Attendance\Reporting\ReportTiles::isDays($key) ? number_format($value, 1) : \App\Support\Format\Ui::number($value))" wire:click="showTile(@js($key))" />
            @endunless
        @endforeach
        </div>
    </details>

    <x-ui.card>
        <div class="flex flex-wrap gap-4 border-b border-gray-200 pb-3">
            @foreach (['days', 'leave', 'credits', 'overtime', 'changes', 'issues'] as $section)
                <x-ui.filter-button wire:click="show('{{ $section }}')" :selected="$tab === $section">{{ __('messages.reporting_ui.tabs.'.$section) }}</x-ui.filter-button>
            @endforeach
        </div>
        @if ($tab === 'days')
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.filter-button wire:click="setDayFilter('review')" :selected="$dayFilter === 'review'">{{ __('messages.clear_screens.needs_review', ['count' => $reviewCount]) }}</x-ui.filter-button>
                <x-ui.filter-button wire:click="setDayFilter('all')" :selected="$dayFilter === 'all'">{{ __('messages.clear_screens.all_days', ['count' => $allDayCount]) }}</x-ui.filter-button>
            </div>
            <p class="mt-2 text-sm text-gray-500">{{ __('messages.clear_screens.select_row') }}</p>
            @if ($dayFilter === 'review' && $reviewCount === 0)<p class="mt-3 text-sm text-green-800">{{ __('messages.clear_screens.no_review') }}</p>@endif
            <x-ui.table class="mt-3">
                <thead><tr>
                    @foreach (['day', 'status', 'in', 'out', 'worked', 'what_wrong'] as $column)
                        <th scope="col">{{ __('messages.clear_screens.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody x-data="{ open: null }">
                @forelse ($days as $day)
                    <tr wire:key="day-{{ $day['work_date'] }}" x-on:click="open = open === '{{ $day['work_date'] }}' ? null : '{{ $day['work_date'] }}'" class="cursor-pointer">
                        <x-ui.td :label="__('messages.clear_screens.day')"><button type="button" x-on:click.stop="open = open === '{{ $day['work_date'] }}' ? null : '{{ $day['work_date'] }}'" x-bind:aria-expanded="(open === '{{ $day['work_date'] }}').toString()" aria-controls="detail-{{ $day['work_date'] }}" class="text-start font-semibold"><span class="block">{{ $day['presentation']['weekday'] }}</span><span class="block font-normal">{{ $day['presentation']['date'] }}</span></button></x-ui.td>
                        <x-ui.td :label="__('messages.clear_screens.status')"><x-ui.badge :tone="$day['presentation']['tone']">{{ $day['presentation']['label'] }}</x-ui.badge></x-ui.td>
                        <x-ui.td :label="__('messages.clear_screens.in')"><bdi dir="ltr" class="font-mono {{ $day['presentation']['late'] || ($day['status'] === 'missing_punch' && ! $day['first_in']) ? 'text-red-700' : '' }}">{{ $day['first_in'] ? substr($day['first_in'], 11, 5) : ($day['status'] === 'missing_punch' ? __('messages.clear_screens.none') : '—') }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.clear_screens.out')"><bdi dir="ltr" class="font-mono {{ $day['presentation']['early'] || ($day['status'] === 'missing_punch' && ! $day['last_out']) ? 'text-red-700' : '' }}">{{ $day['last_out'] ? substr($day['last_out'], 11, 5) : ($day['status'] === 'missing_punch' ? __('messages.clear_screens.none') : '—') }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.clear_screens.worked')"><bdi dir="auto">{{ $day['presentation']['worked'] }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.clear_screens.what_wrong')">{{ implode(' · ', $day['presentation']['problems']) ?: '—' }}</x-ui.td>
                    </tr>
                    <tr id="detail-{{ $day['work_date'] }}" x-show="open === '{{ $day['work_date'] }}'" x-cloak wire:key="detail-{{ $day['work_date'] }}"><td colspan="6" class="p-4 text-sm"><div class="space-y-2">
                        <div class="flex flex-wrap gap-3"><a class="ui-link" href="{{ $dayUrl.'?'.http_build_query(['employee' => $employee->id, 'date' => $day['work_date']]) }}">{{ __('messages.reporting_ui.day_detail') }}</a><a class="ui-link" href="{{ $punchUrl.'?'.http_build_query(['employee' => $employee->id, 'from' => $day['work_date'], 'to' => $day['work_date']]) }}">{{ __('messages.reporting_ui.punches') }}</a></div>
                        @if ($day['holiday_ids'])
                            <p>{{ __('messages.holiday_ui.holiday_names') }}:
                                @foreach ($day['holiday_ids'] as $holidayId)
                                    <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $employee->company).'?'.http_build_query(['holiday' => $holidayId]) }}">{{ $holidayNames[$holidayId] ?? '' }}</a>
                                @endforeach
                            </p>
                        @endif
                        <p>{{ __('messages.reporting_ui.in_out') }}: <bdi dir="ltr">{{ $day['first_in'] ? substr($day['first_in'], 11, 5) : '—' }} / {{ $day['last_out'] ? substr($day['last_out'], 11, 5) : '—' }}</bdi></p>
                        @if (in_array($day['inputs']['work_pattern'] ?? null, ['shift', 'security'], true))
                            <p>{{ __('messages.office_ui.shift') }}: <bdi dir="ltr">{{ \App\Services\Attendance\ShiftDisplay::hours($day['shift_start'], $day['shift_minutes']) ?? '—' }}</bdi> · {{ __('messages.office_ui.shift_sources.'.($day['shift_source'] ?? 'unknown')) }}</p>
                        @endif
                        <p>{{ __('messages.reporting_ui.elapsed') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format((int) $day['observed']['elapsed']) }}</bdi> · {{ __('messages.reporting_ui.presence') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format((int) $day['observed']['presence']) }}</bdi> · {{ __('messages.reporting_ui.short') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format((int) $day['observed']['short']) }}</bdi></p>
                        @if ($overtimeDays->has($day['work_date']))
                            @php $extra = $overtimeDays->get($day['work_date']); @endphp
                            @if ($extra['overtime_minutes'] !== null)
                                <p>{{ __('messages.overtime_ui.overtime') }}: <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($extra['overtime_minutes']) }}</bdi> · {{ __('messages.overtime_ui.basis.'.$extra['overtime_basis']) }}</p>
                            @endif
                            @if ($extra['early_arrival_minutes'] > 0)<p>{{ __('messages.overtime_ui.early_marker') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($extra['early_arrival_minutes']) }}</bdi></p>@endif
                        @endif
                        @foreach (['absence', 'late', 'early', 'exit'] as $kind)
                            @if ($day['minutes'][$kind]['gross'] > 0)
                                <p>{{ app()->getLocale() === 'en' ? ucfirst(__('messages.leave_ui.target_kinds.'.$kind)) : __('messages.leave_ui.target_kinds.'.$kind) }}: {{ __('messages.reporting_ui.gross') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($day['minutes'][$kind]['gross']) }}</bdi> · {{ __('messages.clear_screens.leave_coverage') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($day['minutes'][$kind]['reconciled_by_leave']) }}</bdi> · {{ __('messages.clear_screens.credit_coverage') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($day['minutes'][$kind]['reconciled_by_credit']) }}</bdi> · {{ __('messages.reporting_ui.remaining') }} <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($day['minutes'][$kind]['remaining']) }}</bdi></p>
                            @endif
                        @endforeach
                        @foreach ($detail[$day['work_date']]['explanations'] ?? [] as $explanation)
                            <p>{{ app(\App\Services\Leave\LeaveExplanation::class)->render($explanation) }}</p>
                        @endforeach
                        @if ($day['status'] === 'pending') <p class="font-semibold">{{ __('messages.reporting_ui.pending_note') }}</p> @endif
                    </div></td></tr>
                @empty <tr><td colspan="6" class="p-4 text-sm text-gray-500">{{ $dayFilter === 'review' ? __('messages.clear_screens.no_review') : __('messages.reporting_ui.none') }}</td></tr> @endforelse
                </tbody>
            </x-ui.table>
        @elseif ($tab === 'leave')
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.leave_ui.form_number') }}</th><th scope="col">{{ __('messages.leave_ui.type') }}</th><th scope="col">{{ __('messages.leave_ui.status') }}</th><th scope="col">{{ __('messages.leave_ui.dates') }}</th></tr></thead><tbody>
            @forelse ($requests as $request)
                <tr>
                    <x-ui.td :label="__('messages.leave_ui.form_number')"><bdi dir="ltr">{{ $request->external_id }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.type')">{{ $request->leaveType?->name ?? '—' }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.status')"><x-ui.badge :tone="match ($request->status) { 'approved' => 'green', 'rejected' => 'red', 'pending' => 'indigo', default => 'gray' }">{{ __('messages.leave_ui.statuses.'.$request->status) }}</x-ui.badge>@foreach ($requestVersions->get($request->id, collect()) as $version)<span class="block text-xs"><bdi dir="ltr">{{ $version->snapshot_at?->format('Y-m-d H:i:s') }}</bdi> · {{ __('messages.leave_ui.statuses.'.($version->values['status'] ?? $request->status)) }}</span>@endforeach</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.dates')">{{ $request->start_date?->locale(app()->getLocale())->translatedFormat('j F Y') }} → {{ $request->end_date?->locale(app()->getLocale())->translatedFormat('j F Y') }}</x-ui.td>
                </tr>
            @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('messages.reporting_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        @elseif ($tab === 'credits')
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.leave_ui.form_number') }}</th><th scope="col">{{ __('messages.reporting_ui.created') }}</th><th scope="col">{{ __('messages.reporting_ui.used') }}</th><th scope="col">{{ __('messages.reporting_ui.available') }}</th></tr></thead><tbody>
            @forelse ($credits as $credit)
                <tr>
                    <x-ui.td :label="__('messages.leave_ui.form_number')"><bdi dir="ltr">{{ $credit->sourceDay?->request?->external_id }}</bdi><span class="block text-xs">{{ $credit->sourceDay?->work_date?->locale(app()->getLocale())->translatedFormat('l j F Y') }}</span></x-ui.td>
                    <x-ui.td :label="__('messages.reporting_ui.created')"><bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($credit->minutes) }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.reporting_ui.used')"><bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($used->get($credit->id, collect())->sum('minutes')) }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.reporting_ui.available')"><bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($credit->expires_on && $credit->expires_on->toDateString() < $to ? 0 : max(0, $credit->minutes - $used->get($credit->id, collect())->sum('minutes'))) }}</bdi></x-ui.td>
                </tr>
            @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('messages.reporting_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        @elseif ($tab === 'overtime')
            <h3 class="mt-3 font-bold">{{ __('messages.overtime_ui.section') }}</h3>
            @foreach ($overtime['overtime_by_basis'] as $basis => $minutes)
                <p>{{ __('messages.overtime_ui.basis.'.$basis) }}: <bdi dir="auto">{{ app(\App\Services\Attendance\Reporting\DurationText::class)->format($minutes) }}</bdi></p>
            @endforeach
            <p>{{ __('messages.overtime_ui.earned') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_earned'], 1) }}</bdi> · {{ __('messages.overtime_ui.used') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_used'], 1) }}</bdi> · {{ __('messages.overtime_ui.available') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_available_now'], 1) }}</bdi> · {{ __('messages.overtime_ui.overdue') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_overdue_now'], 1) }}</bdi></p>
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.overtime_ui.entitlement') }}</th><th scope="col">{{ __('messages.overtime_ui.earned') }}</th><th scope="col">{{ __('messages.overtime_ui.used') }}</th><th scope="col">{{ __('messages.overtime_ui.available') }}</th></tr></thead><tbody>
            @forelse ($entitlements as $entitlement)
                <tr>
                    <x-ui.td :label="__('messages.office_ui.date')"><a class="ui-link" href="{{ $dayUrl.'?'.http_build_query(['employee' => $employee->id, 'date' => $entitlement['work_date']]) }}">{{ \Carbon\CarbonImmutable::parse($entitlement['work_date'])->locale(app()->getLocale())->translatedFormat('l j F Y') }}</a></x-ui.td>
                    <x-ui.td :label="__('messages.overtime_ui.entitlement')">{{ __('messages.overtime_ui.entitlement_basis.'.$entitlement['basis']) }}@if ($entitlement['holiday']) · {{ __('messages.overtime_ui.holiday') }}@endif @if ($entitlement['overdue'] > 0)<p class="text-amber-800">{{ __('messages.overtime_ui.overdue_since') }} <bdi dir="ltr">{{ $entitlement['alert_on'] }}</bdi></p>@endif</x-ui.td>
                    <x-ui.td :label="__('messages.overtime_ui.earned')">{{ number_format($entitlement['earned'], 1) }}</x-ui.td>
                    <x-ui.td :label="__('messages.overtime_ui.used')">{{ number_format($entitlement['used'], 1) }}</x-ui.td>
                    <x-ui.td :label="__('messages.overtime_ui.available')">{{ number_format($entitlement['available'], 1) }}@foreach ($consumptions->where('entitlement_id', $entitlement['id']) as $consumption)<p>{{ __('messages.overtime_ui.consumed_by') }} <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $employee->company).'?'.http_build_query(['request' => $consumption['request_id']]) }}"><bdi dir="ltr">{{ $consumption['external_id'] }}</bdi></a> · {{ number_format($consumption['quantity'], 1) }}</p>@endforeach</x-ui.td>
                </tr>
            @empty <tr><td colspan="5" class="p-4"><x-ui.empty :title="__('messages.reporting_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        @elseif ($tab === 'changes')
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.employee_history.title') }}</th></tr></thead><tbody>
            @forelse ($changes as $entry)
                <tr><x-ui.td :label="__('messages.office_ui.date')"><bdi dir="ltr">{{ $entry['version']->effective_from->toDateString() }}</bdi><span class="block text-xs">{{ $entry['version']->actor?->name ?? __('messages.employee_history.system') }}</span></x-ui.td>
                    <x-ui.td :label="__('messages.employee_history.title')">@foreach ($entry['fields'] as $field => $after)<p>{{ __('messages.employee_history.fields.'.$field) }}: <bdi dir="auto">{{ is_array($entry['version']->previous[$field] ?? null) ? implode(', ', $entry['version']->previous[$field]) : ($entry['version']->previous[$field] ?? __('messages.employee_history.empty')) }}</bdi> → <bdi dir="auto">{{ is_array($after) ? implode(', ', $after) : ($after ?? __('messages.employee_history.empty')) }}</bdi></p>@endforeach</x-ui.td></tr>
            @empty <tr><td colspan="2" class="p-4"><x-ui.empty :title="__('messages.reporting_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        @elseif ($tab === 'issues')
            <x-ui.table><thead><tr><th scope="col">{{ __('messages.follow_up_ui.issue') }}</th><th scope="col">{{ __('messages.office_ui.date') }}</th></tr></thead><tbody>
            @forelse ($issues as $issue)
                <tr><x-ui.td :label="__('messages.follow_up_ui.issue')"><x-ui.badge tone="red">{{ __('messages.attendance_ui.issue_types.'.$issue->type) }}</x-ui.badge></x-ui.td><x-ui.td :label="__('messages.office_ui.date')">{{ $issue->date_from?->locale(app()->getLocale())->translatedFormat('l j F Y') ?? '—' }}</x-ui.td></tr>
            @empty <tr><td colspan="2" class="p-4"><x-ui.empty :title="__('messages.reporting_ui.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        @endif
    </x-ui.card>
</div>
