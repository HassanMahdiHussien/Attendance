<x-filament-panels::page>
    @php
        $report = $this->report();
        $summary = $this->summary($report);
        $urls = $this->employeeUrls($report);
        $columns = $tab === 'totals' ? \App\Services\Attendance\Reporting\ExportData::TIME_MANAGEMENT_TOTAL_COLUMNS : \App\Services\Attendance\Reporting\ExportData::TIME_MANAGEMENT_COLUMNS;
        $rows = $tab === 'totals' ? [...$report['totals'], $this->totalRow($report['totals'])] : $this->daysPage($report[$tab], $tab)->items();
    @endphp
    <div class="space-y-5">
        <x-ui.card>
            <div class="flex flex-wrap items-end gap-4">
                <label class="text-sm">{{ __('messages.time_management_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="ui-control block rounded-xl border border-gray-200 px-3 py-2"></label>
                <label class="text-sm">{{ __('messages.time_management_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="ui-control block rounded-xl border border-gray-200 px-3 py-2"></label>
                <label class="text-sm">{{ __('messages.office_ui.branch') }} <select wire:model.live="branchId" class="ui-control block rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="text-sm">{{ __('messages.export_ui.columns.department') }} <select wire:model.live="department" class="ui-control block rounded-xl border border-gray-200 px-3 py-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->departments() as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
                <button type="button" wire:click="requestExport" class="ui-button ui-button-primary">{{ __('messages.time_management_ui.export_excel') }}</button>
            </div>
            <p wire:loading wire:target="from,to,branchId,department" class="mt-3 text-sm">{{ __('messages.time_management_ui.loading') }}</p>
            @if ($exportQueued)<p class="mt-3 text-sm text-green-800">{{ __('messages.export_ui.queued_notice') }} <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceExportsPage::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.export_ui.exports') }}</a></p>@endif
        </x-ui.card>
        <p class="text-sm text-gray-600">{{ __('messages.time_management_ui.time_note') }}</p>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
            @foreach (['employees', 'rows', 'nights', 'fridays', 'holidays', 'overtime', 'missing_check_outs'] as $key)
                <x-ui.card><span class="text-sm text-gray-600">{{ __('messages.time_management_ui.'.$key) }}</span><strong class="mt-1 block font-mono text-xl"><bdi dir="ltr">{{ $summary[$key] }}</bdi></strong></x-ui.card>
            @endforeach
        </div>
            <div class="flex flex-wrap gap-4 border-b border-gray-200">
                <button type="button" wire:click="showNight" class="pb-2 {{ $tab === 'night' ? 'font-semibold border-b-2 border-gray-800' : 'text-gray-600' }}">{{ __('messages.export_ui.time_management.night_sheet') }} <bdi dir="ltr" class="font-mono">({{ count($report['night']) }})</bdi></button>
                <button type="button" wire:click="showFriday" class="pb-2 {{ $tab === 'friday' ? 'font-semibold border-b-2 border-gray-800' : 'text-gray-600' }}">{{ __('messages.export_ui.time_management.friday_sheet') }} <bdi dir="ltr" class="font-mono">({{ count($report['friday']) }})</bdi></button>
                <button type="button" wire:click="showHoliday" class="pb-2 {{ $tab === 'holiday' ? 'font-semibold border-b-2 border-gray-800' : 'text-gray-600' }}">{{ __('messages.export_ui.time_management.holiday_sheet') }} <bdi dir="ltr" class="font-mono">({{ count($report['holiday']) }})</bdi></button>
                <button type="button" wire:click="showTotals" class="pb-2 {{ $tab === 'totals' ? 'font-semibold border-b-2 border-gray-800' : 'text-gray-600' }}">{{ __('messages.export_ui.time_management.totals_sheet') }} <bdi dir="ltr" class="font-mono">({{ count($report['totals']) }})</bdi></button>
            </div>
            <x-ui.card padding="none">
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead><tr>@foreach ($columns as $column)<th scope="col">{{ __('messages.export_ui.columns.'.$column) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($rows as $index => $row)
                                <tr class="border-t {{ ($row['note'] ?? '') !== '' ? 'bg-red-50' : '' }}" wire:key="time-management-{{ $tab }}-{{ $index }}">
                                    @foreach ($columns as $column)
                                        <x-ui.td :label="__('messages.export_ui.columns.'.$column)">
                                            @if ($column === 'employee_code' && isset($urls[$row['employee_code']]))
                                                <a class="ui-link font-mono" href="{{ $urls[$row['employee_code']] }}"><bdi dir="ltr">{{ $row[$column] }}</bdi></a>
                                            @elseif ($column === 'shift' && preg_match('/^(.*) \(([^()]*)\)$/u', $row[$column], $shiftParts))
                                                {{ $shiftParts[1] }} (<bdi dir="ltr" class="font-mono">{{ $shiftParts[2] }}</bdi>)
                                            @elseif (in_array($column, ['employee_code', 'date', 'check_in', 'check_out', 'total_time', 'overtime', 'night_count', 'night_total_time', 'friday_count', 'friday_total_time', 'holiday_count', 'holiday_total_time', 'total_overtime'], true))
                                                <bdi dir="ltr" class="font-mono">{{ $row[$column] }}</bdi>
                                            @else
                                                {{ $row[$column] }}
                                            @endif
                                        </x-ui.td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($columns) }}" class="p-5"><x-ui.empty :title="__('messages.list_empty')" /></td></tr>
                            @endforelse
                        </tbody>
                    </x-ui.table>
                </div>
                @if ($tab !== 'totals')<div class="p-3">{{ $this->daysPage($report[$tab], $tab)->links() }}</div>@endif
            </x-ui.card>
    </div>
</x-filament-panels::page>
