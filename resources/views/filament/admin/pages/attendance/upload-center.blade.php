<x-filament-panels::page>
    <div class="space-y-5" x-data="{ phase: 'idle', progress: 0, uploadError: false, dragging: 0 }" @if($this->hasPendingEmployeeRun()) wire:poll.3s="pollImport" @endif>
        <p class="text-sm text-gray-600">{{ __('messages.clear_screens.upload_context') }}</p>
        <section class="rounded-xl border border-line bg-white p-4 shadow-sm sm:p-5"
            x-on:livewire-upload-start="phase = 'uploading'; progress = 0; uploadError = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress; if (progress >= 100) phase = 'reading'"
            x-on:livewire-upload-finish="phase = 'idle'; $refs.fileInput.value = ''"
            x-on:livewire-upload-error="phase = 'idle'; uploadError = true; $refs.fileInput.value = ''"
            x-on:livewire-upload-cancel="phase = 'idle'; $refs.fileInput.value = ''">
            <h2 class="font-semibold text-ink">{{ __('messages.upload_center.choose') }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ __('messages.clear_screens.upload_instruction') }}</p>
            <label class="mt-3 flex min-h-36 w-full min-w-0 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-line px-4 py-6 text-center focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary"
                x-bind:class="dragging > 0 ? 'border-primary bg-primary-light' : 'bg-white'"
                x-on:dragenter.prevent="dragging++" x-on:dragleave.prevent="dragging = Math.max(0, dragging - 1)"
                x-on:dragover.prevent x-on:drop.prevent="dragging = 0; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }))">
                <input x-ref="fileInput" type="file" multiple wire:model.live="incomingFiles" class="sr-only">
                <span class="rounded-lg border border-line bg-white px-4 py-2 font-semibold text-ink">{{ __('messages.upload_center.choose_files') }}</span>
                <span class="text-sm text-gray-600">{{ __('messages.upload_center.drag_here') }}</span>
            </label>
            <div x-show="phase === 'uploading'" x-cloak role="status" aria-live="polite" class="mt-3 space-y-2 text-sm text-ink">
                <p>{{ __('messages.upload_center.uploading') }} <span x-text="progress + '%'" class="font-mono"></span></p>
                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress" class="h-2 overflow-hidden rounded-full bg-bg"><div class="h-full bg-primary" x-bind:style="'width: ' + progress + '%'"></div></div>
            </div>
            <p x-show="phase === 'reading'" x-cloak role="status" aria-live="polite" class="mt-3 flex items-center gap-2 text-sm text-ink"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-line border-t-primary" aria-hidden="true"></span>{{ __('messages.upload_center.reading') }}</p>
            <p x-show="uploadError" x-cloak role="alert" class="mt-3 text-sm text-red-700">{{ __('messages.upload_center.upload_error') }}</p>
            @error('incomingFiles.*') <p role="alert" class="mt-3 text-sm text-red-700">{{ $message }}</p> @enderror
        </section>

        @if($rows)
            <section class="divide-y divide-line rounded-xl border border-line bg-white shadow-sm">
                @foreach($rows as $index => $row)
                    @php
                        $preview = $row['batch_id'] === null && ($row['run_id'] ?? null) === null ? $this->preview($index) : null;
                        $decision = $row['batch_id'] === null && ($row['run_id'] ?? null) === null ? $this->needsDecision($index) : null;
                    @endphp
                    <div wire:key="upload-row-{{ $index }}" class="min-w-0 space-y-3 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-ink">{{ __('messages.upload_center.kinds.'.$row['kind']) }}</h3>
                                <p class="break-all text-sm text-gray-600" dir="auto">{{ $row['name'] }}</p>
                            </div>
                            @if($row['kind'] !== 'employees' || !$this->hasPendingEmployeeRun())
                                <button type="button" wire:click="remove({{ $index }})" class="ui-link text-sm">{{ __('messages.upload_center.remove') }}</button>
                            @endif
                        </div>
                        @if($row['kind'] === 'announcement' && $row['source_id'])
                            <livewire:attendance.announcement-review :source-id="$row['source_id']" :key="'announcement-'.$row['source_id'].'-'.$index" />
                        @elseif($row['batch_id'] !== null)
                            <p><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone('completed')">{{ __('messages.upload_center.imported') }}</x-ui.badge> <a class="ui-link" href="{{ $this->batchUrl($index) }}">{{ __('messages.upload_center.view_batch') }} #{{ $row['batch_id'] }}</a></p>
                            @if($row['kind'] === 'leave' && ($row['description_count'] ?? 0) > 0)<p><a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveDescriptions::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.leave_descriptions.review_count', ['count' => $row['description_count']]) }}</a></p>@endif
                        @else
                            @if($row['kind'] === 'employees' && ($row['run_id'] ?? null) && !$row['error'])
                                <p><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone('processing')">{{ __('messages.clear_screens.processing') }}</x-ui.badge> {{ __('messages.employee_import_ui.importing', ['count' => \App\Models\EmployeeImportRun::query()->where('company_id', $this->company()->id)->find($row['run_id'])?->row_count ?? 0]) }}</p>
                                <button type="button" wire:click="pollImport" class="ui-link text-sm">{{ __('messages.employee_import_ui.refresh_status') }}</button>
                            @elseif($row['kind'] !== 'employees' && $this->hasPendingEmployeeRun())
                                <p class="text-sm font-medium text-ink">{{ __('messages.upload_center.waiting_for_employees') }}</p>
                            @endif
                            @if($row['kind'] === 'unknown' && ! $row['error'])<p><x-ui.badge tone="red">{{ __('messages.clear_screens.needs_action') }}</x-ui.badge></p>@endif
                            @if($preview || $row['kind'] === 'unknown')<p class="text-sm leading-relaxed text-ink">{{ $this->summary($index) }}</p>@endif
                            @if($row['error']) <p class="text-sm text-red-700"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone('failed')">{{ __('messages.clear_screens.failed') }}</x-ui.badge> {{ $row['error'] }} {{ __('messages.clear_screens.upload_retry') }}</p> @endif
                            @if($decision && $row['kind'] !== 'unknown') <p class="text-sm font-medium text-amber-800"><x-ui.badge tone="red">{{ __('messages.upload_center.needs_decision') }}</x-ui.badge> {{ $decision }}</p> @endif
                            @if($preview && ! $row['error'] && ! $decision && ! $this->hasPendingEmployeeRun()) <p><x-ui.badge tone="green">{{ __('messages.clear_screens.upload_ready') }}</x-ui.badge></p> @endif
                            @if($row['kind'] === 'attendance' && $preview && $this->requiresConfirmation($index))
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model.live="rows.{{ $index }}.confirmed" class="mt-1">{{ __('messages.upload_center.confirm_file') }}</label>
                            @endif
                            @if($row['kind'] === 'attendance' && $preview && ($preview['counts']['new_devices'] ?? 0) > 0)
                                <a class="attendance-device-alert attendance-device-action" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceDevices::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.attendance_ui.new_devices_action', ['count' => $preview['counts']['new_devices']]) }}</a>
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
                            @if($row['kind'] !== 'unknown' && ($row['run_id'] ?? null) === null)
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
                                <details wire:ignore.self class="text-sm"><summary class="cursor-pointer text-primary">{{ __('messages.clear_screens.preview_samples') }}</summary>
                                @foreach($samples as $sample)
                                    <p class="text-xs text-gray-600">
                                        @if(isset($sample['serial'])){{ __('messages.upload_center.problem_device', ['serial' => $sample['serial']]) }}
                                        @elseif(isset($sample['code'], $sample['row'])){{ __('messages.upload_center.problem_code', ['row' => $sample['row'], 'code' => $sample['code']]) }}
                                        @elseif(isset($sample['code'])){{ __('messages.upload_center.problem_unknown_code', ['code' => $sample['code']]) }}
                                        @else{{ __('messages.upload_center.problem_row', ['row' => $sample['row'] ?? '—']) }}@endif
                                    </p>
                                @endforeach
                                </details>
                                <details wire:ignore.self class="text-sm"><summary class="cursor-pointer text-primary">{{ __('messages.upload_center.details') }}</summary>
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
            @php $showImport = collect($rows)->contains(fn (array $row): bool => $row['kind'] !== 'announcement' && $row['batch_id'] === null && ($row['run_id'] ?? null) === null); @endphp
            @if($showImport)
                @php $importableCount = $this->importableCount(); @endphp
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import" @disabled($importableCount === 0 || $this->hasPendingEmployeeRun())
                        data-blocked="{{ $importableCount === 0 || $this->hasPendingEmployeeRun() ? '1' : '0' }}" x-bind:disabled="phase !== 'idle' || $el.dataset.blocked === '1'"
                        class="rounded-lg bg-signal px-4 py-2 font-semibold text-night hover:bg-signal-deep disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="import">{{ $importableCount === 0 ? __('messages.upload_center.import') : trans_choice('messages.upload_center.import_count', $importableCount, ['count' => $importableCount]) }}</span>
                        <span wire:loading wire:target="import">{{ __('messages.upload_center.importing') }}</span>
                    </button>
                    @if($importableCount === 0)<p class="text-sm text-gray-600">{{ __('messages.upload_center.nothing_to_import') }}</p>@endif
                </div>
            @endif
        @endif
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('messages.upload_center.advanced') }}</summary>
            <div class="mt-3 flex flex-wrap gap-4">
                @if(\App\Filament\Admin\Pages\Attendance\UploadAttendance::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadAttendance::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.attendance') }}</a>@endif
                @if(\App\Filament\Admin\Pages\Leave\UploadLeave::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\UploadLeave::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.leave') }}</a><a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\UploadLeaveBalance::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.leave_balance') }}</a>@endif
                @if(\App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees::canAccess())<a class="ui-link" href="{{ \App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.kinds.employees') }}</a>@endif
            </div>
        </details>
    </div>
</x-filament-panels::page>
