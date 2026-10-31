<x-filament-panels::page>
    <div class="space-y-5">
        <section class="rounded-xl border border-line bg-white p-4 shadow-sm sm:p-5">
            <h2 class="font-semibold text-ink">{{ __('messages.upload_center.choose') }}</h2>
            <input type="file" multiple wire:model.live="incomingFiles" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="mt-3 block w-full min-w-0 rounded-lg border border-line p-2 text-sm">
            @error('incomingFiles.*') <p class="mt-2 text-red-700">{{ $message }}</p> @enderror
            <p class="mt-2 text-sm text-gray-600">{{ __('messages.upload_center.accepted') }}</p>
        </section>

        @if($rows)
            <section class="divide-y divide-line rounded-xl border border-line bg-white shadow-sm">
                @foreach($rows as $index => $row)
                    @php
                        $preview = $row['batch_id'] === null ? $this->preview($index) : null;
                        $decision = $row['batch_id'] === null ? $this->needsDecision($index) : null;
                    @endphp
                    <div wire:key="upload-row-{{ $index }}" class="min-w-0 space-y-3 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-ink">{{ __('messages.upload_center.kinds.'.$row['kind']) }}</h3>
                                <p class="break-all text-sm text-gray-600" dir="auto">{{ $row['name'] }}</p>
                            </div>
                            <button type="button" wire:click="remove({{ $index }})" class="ui-link text-sm">{{ __('messages.upload_center.remove') }}</button>
                        </div>
                        @if($row['batch_id'] !== null)
                            <p class="font-medium text-green-700">{{ __('messages.upload_center.imported') }} · <a class="ui-link" href="{{ $this->batchUrl($index) }}">{{ __('messages.upload_center.view_batch') }} #{{ $row['batch_id'] }}</a></p>
                        @else
                            @if($preview || $row['kind'] === 'unknown')<p class="text-sm leading-relaxed text-ink">{{ $this->summary($index) }}</p>@endif
                            @if($row['error']) <p class="text-sm text-red-700">{{ $row['error'] }}</p> @endif
                            @if($decision && $row['kind'] !== 'unknown') <p class="text-sm font-medium text-amber-800">{{ __('messages.upload_center.needs_decision') }}: {{ $decision }}</p> @endif
                            @if($row['kind'] === 'attendance' && $preview && $this->requiresConfirmation($index))
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model.live="rows.{{ $index }}.confirmed" class="mt-1">{{ __('messages.upload_center.confirm_file') }}</label>
                            @endif
                            @if($row['kind'] === 'attendance' && $preview && ($preview['counts']['new_devices'] ?? 0) > 0)
                                <a class="ui-link me-4 inline-block text-sm" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceDevices::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.review_devices') }}</a>
                            @endif
                            @if($preview && $row['kind'] === 'employees')
                                @foreach(\App\Services\Employees\ImportPreview::fromArray($preview)->unmatchedBranches() as $unmatched => $item)
                                    <div class="space-y-2 border-t border-line pt-3 text-sm" wire:key="branch-{{ $index }}-{{ $unmatched }}">
                                        <p>{{ __('messages.employee_import_ui.unmatched_branch', ['branch' => $item['name'], 'count' => $item['count']]) }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <select wire:model="branchChoices.{{ $index }}.{{ $unmatched }}" class="max-w-full rounded-lg border border-line bg-white p-2">
                                                <option value="">{{ __('messages.employee_import_ui.choose_branch') }}</option>
                                                @foreach(\App\Models\Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->get() as $branch)
                                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="linkBranch({{ $index }}, {{ $unmatched }})" class="rounded-lg border border-line px-3 py-2">{{ __('messages.employee_import_ui.link_branch') }}</button>
                                            @can('create', \App\Models\Branch::class)
                                                <select wire:model="jurisdictionChoices.{{ $index }}.{{ $unmatched }}" class="max-w-full rounded-lg border border-line bg-white p-2">
                                                    <option value="">{{ __('messages.employee_import_ui.choose_jurisdiction') }}</option>
                                                    <option value="federal_iraq">{{ __('Federal Iraq') }}</option>
                                                    <option value="kurdistan_region">{{ __('Kurdistan Region') }}</option>
                                                </select>
                                                <button type="button" wire:click="createBranch({{ $index }}, {{ $unmatched }})" class="rounded-lg border border-line px-3 py-2">{{ __('messages.employee_import_ui.create_branch') }}</button>
                                            @endcan
                                        </div>
                                        @error('branchChoices.'.$index.'.'.$unmatched) <p class="text-red-700">{{ $message }}</p> @enderror
                                        @error('jurisdictionChoices.'.$index.'.'.$unmatched) <p class="text-red-700">{{ $message }}</p> @enderror
                                    </div>
                                @endforeach
                            @endif
                            @if($row['kind'] !== 'unknown')
                                <button type="button" wire:click="change({{ $index }})" class="ui-link inline-block text-sm">{{ __('messages.upload_center.change') }}</button>
                            @endif
                            @if($row['editing'] || $row['needs_mapping'] || ($row['kind'] === 'leave_balance' && !$preview))
                                <div class="space-y-3 border-t border-line pt-3">
                                    @if($row['needs_mapping'])
                                        <p class="text-sm font-medium">{{ __('messages.upload_center.match_columns') }}</p>
                                        @php $fields = match($row['kind']) { 'attendance' => \App\Services\Attendance\TransactionFile::FIELDS, 'leave' => \App\Services\Leave\LeaveFile::FIELDS, 'leave_balance' => \App\Services\Leave\LeaveBalanceFile::FIELDS, default => \App\Services\Employees\ColumnMapping::FIELDS }; @endphp
                                        @foreach($row['headers'] as $column => $header)
                                            <label class="flex min-w-0 flex-wrap items-center justify-between gap-2 border-b border-line py-2 text-sm" wire:key="map-{{ $index }}-{{ $column }}">
                                                <span class="min-w-0 break-words">{{ $header }} @if($row['subheaders'][$column] ?? null) · {{ $row['subheaders'][$column] }} @endif</span>
                                                <select wire:model="rows.{{ $index }}.mapping.{{ $column }}" class="max-w-full rounded-lg border border-line bg-white p-2">
                                                    <option value="">{{ __('messages.upload_center.unmapped') }}</option>
                                                    @foreach($fields as $field)
                                                        <option value="{{ $field }}">{{ $this->fieldLabel($row['kind'], $field) }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endforeach
                                    @endif
                                    @if($row['kind'] === 'attendance')
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="text-sm">{{ __('messages.upload_center.from') }}<input type="datetime-local" wire:model="rows.{{ $index }}.settings.from" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                            <label class="text-sm">{{ __('messages.upload_center.to') }}<input type="datetime-local" wire:model="rows.{{ $index }}.settings.to" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                        </div>
                                        <fieldset class="flex flex-wrap gap-3 text-sm"><legend>{{ __('messages.upload_center.branches') }}</legend>
                                            @foreach(\App\Models\Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->get() as $branch)
                                                <label><input type="checkbox" wire:model="rows.{{ $index }}.settings.branch_ids" value="{{ $branch->id }}"> {{ $branch->name }}</label>
                                            @endforeach
                                        </fieldset>
                                    @elseif($row['kind'] === 'leave')
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="text-sm">{{ __('messages.upload_center.scope') }}<select wire:model="rows.{{ $index }}.settings.scope" class="mt-1 block w-full rounded-lg border border-line p-2"><option value="partial">{{ __('messages.leave_ui.partial') }}</option><option value="full">{{ __('messages.leave_ui.full') }}</option></select></label>
                                            <label class="text-sm">{{ __('messages.upload_center.snapshot') }}<input type="text" wire:model="rows.{{ $index }}.settings.snapshot_at" placeholder="{{ __('messages.leave_ui.snapshot_format') }}" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                            <label class="text-sm">{{ __('messages.upload_center.from') }}<input type="date" wire:model="rows.{{ $index }}.settings.period_from" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                            <label class="text-sm">{{ __('messages.upload_center.to') }}<input type="date" wire:model="rows.{{ $index }}.settings.period_to" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                        </div>
                                    @elseif($row['kind'] === 'leave_balance')
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="text-sm">{{ __('messages.leave_balance_ui.balance_date') }}<input type="date" wire:model="rows.{{ $index }}.settings.date" max="{{ today('Asia/Baghdad')->toDateString() }}" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                            <label class="text-sm">{{ __('messages.leave_balance_ui.balance_year') }}<input type="number" wire:model="rows.{{ $index }}.settings.year" min="2000" max="2100" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                        </div>
                                    @elseif($row['kind'] === 'employees')
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="text-sm">{{ __('messages.employee_import_ui.mode') }}<select wire:model="rows.{{ $index }}.settings.mode" class="mt-1 block w-full rounded-lg border border-line p-2"><option value="partial">{{ __('messages.employee_import_ui.partial') }}</option><option value="full">{{ __('messages.employee_import_ui.full') }}</option></select></label>
                                            <label class="text-sm">{{ __('messages.employee_import_ui.effective_date') }}<input type="date" wire:model="rows.{{ $index }}.settings.date" class="mt-1 block w-full rounded-lg border border-line p-2" dir="ltr"></label>
                                        </div>
                                    @endif
                                    <button type="button" wire:click="refreshRow({{ $index }})" class="rounded-lg border border-line bg-white px-4 py-2 text-sm">{{ __('messages.upload_center.update_preview') }}</button>
                                    @error('mapping') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                                    @error('rows.'.$index.'.mapping') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            @if($preview)
                                @php $samples = collect($preview['samples'] ?? [])->flatten(1)->take(3); @endphp
                                @foreach($samples as $sample)
                                    <p class="text-xs text-gray-600">
                                        @if(isset($sample['serial'])){{ __('messages.upload_center.problem_device', ['serial' => $sample['serial']]) }}
                                        @elseif(isset($sample['code'], $sample['row'])){{ __('messages.upload_center.problem_code', ['row' => $sample['row'], 'code' => $sample['code']]) }}
                                        @elseif(isset($sample['code'])){{ __('messages.upload_center.problem_unknown_code', ['code' => $sample['code']]) }}
                                        @else{{ __('messages.upload_center.problem_row', ['row' => $sample['row'] ?? '—']) }}@endif
                                    </p>
                                @endforeach
                                <details class="text-sm"><summary class="cursor-pointer text-primary">{{ __('messages.upload_center.details') }}</summary>
                                    <div class="mt-2 grid gap-1 sm:grid-cols-2">
                                        @foreach($preview['counts'] ?? [] as $key => $count)
                                            <p>{{ $this->countLabel($row['kind'], $key) }}: <bdi dir="ltr">{{ $count }}</bdi></p>
                                        @endforeach
                                        @if($row['kind'] === 'employees')
                                            <p>{{ __('messages.upload_center.counts.new') }}: {{ \App\Services\Employees\ImportPreview::fromArray($preview)->added() }}</p>
                                            <p>{{ __('messages.upload_center.counts.changed') }}: {{ \App\Services\Employees\ImportPreview::fromArray($preview)->changed() }}</p>
                                            <p>{{ __('messages.upload_center.counts.held') }}: {{ \App\Services\Employees\ImportPreview::fromArray($preview)->held() }}</p>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        @endif
                    </div>
                @endforeach
            </section>
            <button type="button" wire:click="import" wire:loading.attr="disabled" class="rounded-lg bg-signal px-4 py-2 font-semibold text-night hover:bg-signal-deep disabled:opacity-50">{{ __('messages.upload_center.import') }}</button>
        @endif
        <details class="rounded-xl border border-line bg-white p-4 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('messages.upload_center.advanced') }}</summary>
            <div class="mt-3 flex flex-wrap gap-4">
                @if(\App\Filament\Admin\Pages\Attendance\UploadAttendance::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadAttendance::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.attendance') }}</a>@endif
                @if(\App\Filament\Admin\Pages\Leave\UploadLeave::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\UploadLeave::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.leave') }}</a><a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\UploadLeaveBalance::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.leave_balance') }}</a>@endif
                @if(\App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.employees') }}</a>@endif
            </div>
        </details>
    </div>
</x-filament-panels::page>
