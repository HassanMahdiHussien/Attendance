<x-filament-panels::page>
    <div class="space-y-6">
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold">{{ __('messages.employee_import_ui.upload') }}</h2>
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\UploadLeaveBalance::getUrl(panel: 'admin', tenant: \Filament\Facades\Filament::getTenant()) }}">{{ __('messages.leave_balance_ui.upload') }}</a>
            <p>{{ __('messages.employee_import_ui.instructions') }}</p>
            <a class="underline text-primary-700" href="{{ route('employees.import.template', ['company' => \Filament\Facades\Filament::getTenant()->id]) }}">{{ __('Download Excel template') }}</a>
            <a class="underline text-primary-700" href="{{ route('employees.import.template.csv', ['company' => \Filament\Facades\Filament::getTenant()->id]) }}">{{ __('Download CSV template') }}</a>
            <form wire:submit="prepareMapping" class="space-y-3">
                <input type="file" name="sourceFile" wire:model="sourceFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block w-full rounded-xl border border-gray-200 bg-white p-2">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-4">
                    <label>{{ __('messages.employee_import_ui.mode') }}
                        <select wire:model="mode" class="rounded-xl border border-gray-200 bg-white p-2">
                            <option value="full">{{ __('messages.employee_import_ui.full') }}</option>
                            <option value="partial">{{ __('messages.employee_import_ui.partial') }}</option>
                        </select>
                    </label>
                    <label>{{ __('messages.employee_import_ui.effective_date') }}
                        <input type="date" wire:model="effectiveDate" class="rounded-xl border border-gray-200 bg-white p-2" dir="ltr">
                    </label>
                </div>
                @error('mode') <p class="text-red-600">{{ $message }}</p> @enderror
                @error('effectiveDate') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile">{{ __('messages.employee_import_ui.map_columns') }}</x-filament::button>
            </form>
        </section>

        @if ($step === 'mapping')
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.employee_import_ui.mapping') }}</h2>
                <p>{{ __('messages.employee_import_ui.mapping_help') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-start"><thead><tr><th class="p-2">{{ __('messages.employee_import_ui.column') }}</th><th class="p-2">{{ __('messages.employee_import_ui.header') }}</th><th class="p-2">{{ __('messages.employee_import_ui.sample') }}</th><th class="p-2">{{ __('messages.employee_import_ui.field') }}</th></tr></thead>
                        <tbody>
                            @foreach ($headers as $index => $header)
                                <tr class="border-t" wire:key="mapping-{{ $index }}">
                                    <td class="p-2" dir="ltr">{{ $index + 1 }}</td>
                                    <td class="p-2">{{ $header ?: __('messages.employee_import_ui.blank_header') }}</td>
                                    <td class="p-2"><bdi dir="{{ in_array($mapping[$index] ?? '', ['employee_code', 'manager_code', 'email', 'fallback_notification_email', 'schedule_start', 'schedule_end'], true) ? 'ltr' : 'auto' }}">{{ $samples[$index] ?? '' }}</bdi></td>
                                    <td class="p-2"><select wire:model.live="mapping.{{ $index }}" class="rounded-xl border border-gray-200 bg-white p-2">
                                        <option value="">{{ __('messages.employee_import_ui.unmapped') }}</option>
                                        @foreach (\App\Services\Employees\ColumnMapping::FIELDS as $field)
                                            <option value="{{ $field }}">{{ __('messages.employee_import_ui.fields.'.$field) }}</option>
                                        @endforeach
                                    </select></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('mapping') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="previewMappedImport">{{ __('Preview file') }}</x-filament::button>
            </section>
        @endif

        @if ($summary)
            <section class="space-y-2 rounded-xl border border-green-500 p-4">
                @if (isset($summary['counts']))
                    <p>{{ __('messages.employee_import_ui.completed', $summary['counts']) }}</p>
                    @if (($summary['skipped'] ?? 0) > 0)<p>{{ __('messages.employee_import_ui.skipped', ['count' => $summary['skipped']]) }}</p>@endif
                    <a class="underline" href="{{ route('employees.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'batch' => $summary['batch_id']]) }}">{{ __('messages.employee_import_ui.download_original') }}</a>
                @else
                    <p>{{ __('Import completed: :added added, :updated updated.', $summary) }}</p>
                @endif
            </section>
        @endif

        @if ($this->recentBatches()->isNotEmpty())
            <section class="space-y-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.employee_import_ui.previous') }}</h2>
                @foreach ($this->recentBatches() as $batch)
                    <p wire:key="batch-{{ $batch->id }}"><bdi dir="ltr">{{ $batch->created_at->format('Y-m-d') }}</bdi> · {{ __('messages.employee_import_ui.'.$batch->mode) }} ·
                        <a class="underline" href="{{ route('employees.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'batch' => $batch->id]) }}">{{ __('messages.employee_import_ui.download_original') }}</a>
                    </p>
                @endforeach
            </section>
        @endif

        @if ($previewRows !== null)
            <section class="space-y-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                @foreach ($previewRows as $row)
                    @foreach ($row['errors'] as $error) <p class="text-red-600">{{ $error }}</p> @endforeach
                    @foreach ($row['warnings'] as $warning) <p class="text-amber-700">{{ $warning }}</p> @endforeach
                @endforeach
            </section>
        @endif

        @if ($step === 'preview' && $this->mappedPreview())
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.employee_import_ui.preview') }}</h2>
                <p>{{ __('messages.employee_import_ui.counts', ['new' => $this->mappedPreview()->added(), 'changed' => $this->mappedPreview()->changed(), 'unchanged' => $this->mappedPreview()->unchanged(), 'missing' => count($this->mappedPreview()->missing), 'errors' => $this->mappedPreview()->errors()]) }}</p>
                @if ($this->mappedPreview()->held() > 0)
                    <p>{{ __('messages.employee_import_ui.held_count', ['count' => $this->mappedPreview()->held()]) }}</p>
                    @foreach ($this->mappedPreview()->unmatchedBranches() as $index => $item)
                        <div class="space-y-2 border-t border-gray-200 pt-3" wire:key="unmatched-{{ $index }}">
                            <p>{{ __('messages.employee_import_ui.unmatched_branch', ['branch' => $item['name'], 'count' => $item['count']]) }}</p>
                            @if (auth()->user()->can('create', \App\Models\Branch::class))
                                <div class="flex flex-wrap gap-2">
                                    <select wire:model="branchChoices.{{ $index }}" class="rounded-lg border border-gray-200 bg-white p-2">
                                        <option value="">{{ __('messages.employee_import_ui.choose_branch') }}</option>
                                        @foreach ($this->availableBranches() as $branch)
                                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-filament::button color="gray" wire:click="linkUnmatched({{ $index }})">{{ __('messages.employee_import_ui.link_branch') }}</x-filament::button>
                                    <select wire:model="jurisdictionChoices.{{ $index }}" class="rounded-lg border border-gray-200 bg-white p-2">
                                        <option value="">{{ __('messages.employee_import_ui.choose_jurisdiction') }}</option>
                                        <option value="federal_iraq">{{ __('Federal Iraq') }}</option>
                                        <option value="kurdistan_region">{{ __('Kurdistan Region') }}</option>
                                    </select>
                                    <x-filament::button color="gray" wire:click="createUnmatched({{ $index }})">{{ __('messages.employee_import_ui.create_branch') }}</x-filament::button>
                                </div>
                                @error('branchChoices.'.$index) <p class="text-red-600">{{ $message }}</p> @enderror
                                @error('jurisdictionChoices.'.$index) <p class="text-red-600">{{ $message }}</p> @enderror
                            @endif
                        </div>
                    @endforeach
                @endif
                @if ($this->mappedPreview()->alreadyImported)
                    <p class="text-amber-700">{{ __('messages.employee_import_ui.already_imported', $this->mappedPreview()->alreadyImported) }}</p>
                @endif
                <div class="overflow-x-auto"><table class="w-full text-start">
                    <thead><tr><th class="p-2">{{ __('Row') }}</th><th class="p-2">{{ __('Status') }}</th><th class="p-2">{{ __('Name') }}</th><th class="p-2">{{ __('Number') }}</th><th class="p-2">{{ __('Branch') }}</th><th class="p-2">{{ __('Changes / notes') }}</th></tr></thead>
                    <tbody>
                        @foreach ($this->mappedPreview()->rows as $row)
                            <tr class="border-t" wire:key="preview-{{ $loop->index }}">
                                <td class="p-2">{{ $row['number'] }}</td><td class="p-2">{{ __('messages.employee_import_ui.status.'.$row['status']) }}</td>
                                <td class="p-2">{{ $row['name'] }}</td><td class="p-2"><bdi dir="ltr">{{ $row['code'] }}</bdi></td><td class="p-2">{{ $row['branch'] }}</td>
                                <td class="p-2">
                                    @foreach ($row['diff'] as $field => $change)
                                        <p>{{ __('messages.employee_import_ui.fields.'.$field) }}:
                                            <bdi dir="{{ in_array($field, ['email', 'fallback_notification_email', 'schedule_start', 'schedule_end', 'manager_employee_id'], true) ? 'ltr' : 'auto' }}">{{ is_array($change['before']) ? implode(', ', $change['before']) : ($change['before'] ?? '—') }}</bdi>
                                            → <bdi dir="{{ in_array($field, ['email', 'fallback_notification_email', 'schedule_start', 'schedule_end', 'manager_employee_id'], true) ? 'ltr' : 'auto' }}">{{ is_array($change['after']) ? implode(', ', $change['after']) : ($change['after'] ?? '—') }}</bdi>
                                        </p>
                                    @endforeach
                                    @foreach ($row['errors'] as $error) <p class="text-red-600">{{ $error }}</p> @endforeach
                                    @foreach ($row['warnings'] as $warning) <p class="text-amber-700">{{ $warning }}</p> @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if ($this->mappedPreview()->missing)
                    <h3 class="font-bold">{{ __('messages.employee_import_ui.missing') }}</h3>
                    <ul>@foreach ($this->mappedPreview()->missing as $employee)<li><bdi dir="ltr">{{ $employee['code'] }}</bdi> — {{ $employee['name'] }}</li>@endforeach</ul>
                @endif
                @if ($this->mappedPreview()->token && $this->mappedPreview()->errors() === 0)
                    <x-filament::button wire:click="confirmMappedImport">{{ __('Confirm import') }}</x-filament::button>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
