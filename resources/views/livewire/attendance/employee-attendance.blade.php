<div class="space-y-5">
    <x-ui.card>
        <h2 class="text-lg font-bold text-ink">{{ __('messages.attendance.title') }}</h2>
        <p class="text-sm text-gray-500"><bdi dir="ltr">{{ $from }} → {{ $to }}</bdi></p>
        <p class="text-sm text-gray-500">{{ __('messages.reporting_ui.freshness') }}: <bdi dir="ltr">{{ $freshness['runs']['reconciliation'] ?? $freshness['runs']['assessment'] ?? '—' }}</bdi></p>
        @if ($freshness['results_updating']) <p class="text-sm text-amber-800">{{ __('messages.reporting_ui.updating') }}</p> @endif
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" wire:click="period('current')" class="ui-link">{{ __('messages.reporting_ui.current_month') }}</button>
            <button type="button" wire:click="period('previous')" class="ui-link">{{ __('messages.reporting_ui.previous_month') }}</button>
            <button type="button" wire:click="period('last_30')" class="ui-link">{{ __('messages.reporting_ui.last_30') }}</button>
        </div>
        <form wire:submit="apply" class="mt-3 flex flex-wrap items-end gap-3">
            <label class="text-sm">{{ __('messages.reporting_ui.from') }} <input type="date" wire:model="from" class="ui-control block rounded-xl border border-gray-200 px-3 py-2" /></label>
            <label class="text-sm">{{ __('messages.reporting_ui.to') }} <input type="date" wire:model="to" class="ui-control block rounded-xl border border-gray-200 px-3 py-2" /></label>
            <button type="submit" class="ui-link">{{ __('messages.reporting_ui.apply') }}</button>
        </form>
        @error('period') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <x-ui.card>
        <h3 class="font-bold text-ink">{{ __('messages.export_ui.export') }}</h3>
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
            <button type="submit" class="ui-link">{{ __('messages.export_ui.create') }}</button>
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceExportsPage::getUrl(panel: 'admin', tenant: $employee->company) }}">{{ __('messages.export_ui.exports') }}</a>
        </form>
        @if ($exportQueued) <p class="mt-2 text-sm text-green-800">{{ __('messages.export_ui.queued_notice') }}</p> @endif
        @error('export') <p class="text-red-700">{{ $message }}</p> @enderror
    </x-ui.card>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($tiles as $key => $value)
            <button type="button" wire:click="showTile(@js($key))" class="rounded-2xl border border-gray-200 bg-white p-3 sm:p-4 text-start hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">
                <span class="block text-sm text-gray-500">{{ \App\Services\Attendance\Reporting\ReportTiles::label($key) }}</span>
                <strong class="mt-1 block font-mono text-xl text-ink"><bdi dir="ltr">{{ \App\Services\Attendance\Reporting\ReportTiles::isMinutes($key) ? sprintf('%d:%02d', intdiv($value, 60), $value % 60) : (\App\Services\Attendance\Reporting\ReportTiles::isDays($key) ? number_format($value, 1) : \App\Support\Format\Ui::number($value)) }}</bdi></strong>
            </button>
        @endforeach
    </div>

    <x-ui.card>
        <div class="flex flex-wrap gap-4 border-b border-gray-200 pb-3">
            @foreach (['days', 'leave', 'credits', 'overtime', 'changes', 'issues'] as $section)
                <button type="button" wire:click="show('{{ $section }}')" class="{{ $tab === $section ? 'font-bold text-ink' : 'ui-link' }}">{{ __('messages.reporting_ui.tabs.'.$section) }}</button>
            @endforeach
        </div>
        @if ($tab === 'days')
            <div class="mt-3 space-y-3">
                @forelse ($days as $day)
                    <div class="border-b border-gray-100 pb-3 text-sm">
                        <div class="flex flex-wrap items-center gap-3"><bdi dir="ltr">{{ $day['work_date'] }}</bdi><strong>{{ __('messages.office_ui.statuses.'.$day['status']) }}</strong>
                            <a class="ui-link" href="{{ $dayUrl.'?'.http_build_query(['employee' => $employee->id, 'date' => $day['work_date']]) }}">{{ __('messages.reporting_ui.day_detail') }}</a>
                            <a class="ui-link" href="{{ $punchUrl.'?'.http_build_query(['employee' => $employee->id, 'from' => $day['work_date'], 'to' => $day['work_date']]) }}">{{ __('messages.reporting_ui.punches') }}</a>
                        </div>
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
                        <p>{{ __('messages.reporting_ui.elapsed') }} <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv((int) $day['observed']['elapsed'], 60), $day['observed']['elapsed'] % 60) }}</bdi> · {{ __('messages.reporting_ui.presence') }} <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv((int) $day['observed']['presence'], 60), $day['observed']['presence'] % 60) }}</bdi> · {{ __('messages.reporting_ui.short') }} <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv((int) $day['observed']['short'], 60), $day['observed']['short'] % 60) }}</bdi></p>
                        @if ($overtimeDays->has($day['work_date']))
                            @php $extra = $overtimeDays->get($day['work_date']); @endphp
                            @if ($extra['overtime_minutes'] !== null)
                                <p>{{ __('messages.overtime_ui.overtime') }}: <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv($extra['overtime_minutes'], 60), $extra['overtime_minutes'] % 60) }}</bdi> · {{ __('messages.overtime_ui.basis.'.$extra['overtime_basis']) }}</p>
                            @endif
                            @if ($extra['early_arrival_minutes'] > 0)<p>{{ __('messages.overtime_ui.early_marker') }} <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv($extra['early_arrival_minutes'], 60), $extra['early_arrival_minutes'] % 60) }}</bdi></p>@endif
                        @endif
                        @foreach (['absence', 'late', 'early', 'exit'] as $kind)
                            @if ($day['minutes'][$kind]['gross'] > 0)
                                <p>{{ app()->getLocale() === 'en' ? ucfirst(__('messages.leave_ui.target_kinds.'.$kind)) : __('messages.leave_ui.target_kinds.'.$kind) }}: {{ __('messages.reporting_ui.gross') }} <bdi dir="ltr">{{ $day['minutes'][$kind]['gross'] }}</bdi> · {{ __('messages.reporting_ui.reconciled') }} <bdi dir="ltr">{{ $day['minutes'][$kind]['reconciled_by_leave'] + $day['minutes'][$kind]['reconciled_by_credit'] }}</bdi> · {{ __('messages.reporting_ui.remaining') }} <bdi dir="ltr">{{ $day['minutes'][$kind]['remaining'] }}</bdi> {{ __('messages.reporting_ui.minutes') }}</p>
                            @endif
                        @endforeach
                        @foreach ($detail[$day['work_date']]['explanations'] ?? [] as $explanation)
                            <p>{{ app(\App\Services\Leave\LeaveExplanation::class)->render($explanation) }}</p>
                        @endforeach
                        @if ($day['status'] === 'pending') <p class="font-semibold">{{ __('messages.reporting_ui.pending_note') }}</p> @endif
                    </div>
                @empty <p class="mt-3 text-sm text-gray-500">{{ __('messages.reporting_ui.none') }}</p> @endforelse
            </div>
        @elseif ($tab === 'leave')
            @forelse ($requests as $request)
                <div class="border-b border-gray-100 py-3 text-sm">
                    <p><bdi dir="ltr">{{ $request->external_id }}</bdi> · {{ $request->leaveType?->name }} · {{ __('messages.leave_ui.statuses.'.$request->status) }} · <bdi dir="ltr">{{ $request->start_date?->toDateString() }} → {{ $request->end_date?->toDateString() }}</bdi></p>
                    @foreach ($requestVersions->get($request->id, collect()) as $version)
                        <p class="mt-1 text-gray-500"><bdi dir="ltr">{{ $version->snapshot_at?->format('Y-m-d H:i:s') }}</bdi> · {{ __('messages.leave_ui.statuses.'.($version->values['status'] ?? $request->status)) }}</p>
                    @endforeach
                </div>
            @empty <p class="mt-3 text-sm text-gray-500">{{ __('messages.reporting_ui.none') }}</p> @endforelse
        @elseif ($tab === 'credits')
            @forelse ($credits as $credit)
                <p class="border-b border-gray-100 py-3 text-sm"><bdi dir="ltr">{{ $credit->sourceDay?->request?->external_id }} · {{ $credit->sourceDay?->work_date?->toDateString() }}</bdi> · {{ __('messages.reporting_ui.created') }} <bdi dir="ltr">{{ $credit->minutes }}</bdi> · {{ __('messages.reporting_ui.used') }} <bdi dir="ltr">{{ $used->get($credit->id, collect())->sum('minutes') }}</bdi> · {{ __('messages.reporting_ui.available') }} <bdi dir="ltr">{{ $credit->expires_on && $credit->expires_on->toDateString() < $to ? 0 : max(0, $credit->minutes - $used->get($credit->id, collect())->sum('minutes')) }}</bdi></p>
            @empty <p class="mt-3 text-sm text-gray-500">{{ __('messages.reporting_ui.none') }}</p> @endforelse
        @elseif ($tab === 'overtime')
            <h3 class="mt-3 font-bold">{{ __('messages.overtime_ui.section') }}</h3>
            @foreach ($overtime['overtime_by_basis'] as $basis => $minutes)
                <p>{{ __('messages.overtime_ui.basis.'.$basis) }}: <bdi dir="ltr">{{ sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60) }}</bdi></p>
            @endforeach
            <p>{{ __('messages.overtime_ui.earned') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_earned'], 1) }}</bdi> · {{ __('messages.overtime_ui.used') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_used'], 1) }}</bdi> · {{ __('messages.overtime_ui.available') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_available_now'], 1) }}</bdi> · {{ __('messages.overtime_ui.overdue') }} <bdi dir="ltr">{{ number_format($overtime['entitlements_overdue_now'], 1) }}</bdi></p>
            @forelse ($entitlements as $entitlement)
                <div class="border-b border-gray-100 py-3 text-sm">
                    <a class="ui-link" href="{{ $dayUrl.'?'.http_build_query(['employee' => $employee->id, 'date' => $entitlement['work_date']]) }}"><bdi dir="ltr">{{ $entitlement['work_date'] }}</bdi></a> · {{ __('messages.overtime_ui.entitlement_basis.'.$entitlement['basis']) }}@if ($entitlement['holiday']) · {{ __('messages.overtime_ui.holiday') }}@endif
                    <p>{{ __('messages.overtime_ui.earned') }} <bdi dir="ltr">{{ number_format($entitlement['earned'], 1) }}</bdi> · {{ __('messages.overtime_ui.used') }} <bdi dir="ltr">{{ number_format($entitlement['used'], 1) }}</bdi> · {{ __('messages.overtime_ui.available') }} <bdi dir="ltr">{{ number_format($entitlement['available'], 1) }}</bdi></p>
                    @if ($entitlement['overdue'] > 0)<p class="text-amber-800">{{ __('messages.overtime_ui.overdue_since') }} <bdi dir="ltr">{{ $entitlement['alert_on'] }}</bdi></p>@endif
                    @foreach ($consumptions->where('entitlement_id', $entitlement['id']) as $consumption)
                        <p>{{ __('messages.overtime_ui.consumed_by') }} <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $employee->company).'?'.http_build_query(['request' => $consumption['request_id']]) }}"><bdi dir="ltr">{{ $consumption['external_id'] }}</bdi></a> · <bdi dir="ltr">{{ number_format($consumption['quantity'], 1) }}</bdi></p>
                    @endforeach
                </div>
            @empty <p>{{ __('messages.reporting_ui.none') }}</p> @endforelse
        @elseif ($tab === 'changes')
            @forelse ($changes as $change)
                <div class="border-b border-gray-100 py-3 text-sm">
                    <p><bdi dir="ltr">{{ $change->effective_from->toDateString() }}</bdi> · {{ $change->actor?->name ?? __('messages.employee_history.system') }}</p>
                    @foreach ($change->changes as $field => $after)
                        <p>{{ __('messages.employee_history.fields.'.$field) }}: <bdi dir="auto">{{ is_array($change->previous[$field] ?? null) ? implode(', ', $change->previous[$field]) : ($change->previous[$field] ?? __('messages.employee_history.empty')) }}</bdi> → <bdi dir="auto">{{ is_array($after) ? implode(', ', $after) : ($after ?? __('messages.employee_history.empty')) }}</bdi></p>
                    @endforeach
                </div>
            @empty <p class="mt-3 text-sm text-gray-500">{{ __('messages.reporting_ui.none') }}</p> @endforelse
        @elseif ($tab === 'issues')
            @forelse ($issues as $issue)
                <p class="border-b border-gray-100 py-3 text-sm">{{ __('messages.attendance_ui.issue_types.'.$issue->type) }} · <bdi dir="ltr">{{ $issue->date_from?->toDateString() }}</bdi></p>
            @empty <p class="mt-3 text-sm text-gray-500">{{ __('messages.reporting_ui.none') }}</p> @endforelse
        @endif
    </x-ui.card>
</div>
