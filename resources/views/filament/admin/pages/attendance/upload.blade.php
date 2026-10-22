<x-filament-panels::page>
    <div class="space-y-6">
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold">{{ __('messages.attendance_ui.file') }}</h2>
            <form wire:submit="prepare" class="space-y-3">
                <input type="file" name="sourceFile" wire:model="sourceFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block w-full rounded-xl border border-gray-200 p-2">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile">{{ __('messages.attendance_ui.continue') }}</x-filament::button>
            </form>
        </section>

        @if ($step === 'mapping')
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.mapping') }}</h2>
                <p>{{ __('messages.attendance_ui.mapping_help') }}</p>
                <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr><th class="p-2">{{ __('messages.attendance_ui.column') }}</th><th class="p-2">{{ __('messages.attendance_ui.header') }}</th><th class="p-2">{{ __('messages.attendance_ui.field') }}</th></tr></thead>
                    <tbody>@foreach ($headers as $index => $header)
                        <tr class="border-t" wire:key="attendance-mapping-{{ $index }}"><td class="p-2" dir="ltr">{{ $index + 1 }}</td><td class="p-2">{{ $header ?: __('messages.attendance_ui.blank_header') }}</td>
                            <td class="p-2"><select wire:model.live="mapping.{{ $index }}" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.attendance_ui.unmapped') }}</option>
                                @foreach (\App\Services\Attendance\TransactionFile::FIELDS as $field)<option value="{{ $field }}">{{ __('messages.attendance_ui.fields.'.$field) }}</option>@endforeach
                            </select></td></tr>
                    @endforeach</tbody></table></div>
                @error('mapping') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="setCoverageStep">{{ __('messages.attendance_ui.continue') }}</x-filament::button>
            </section>
        @endif

        @if (in_array($step, ['coverage', 'preview'], true))
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.coverage') }}</h2>
                <div class="flex flex-wrap gap-4"><label>{{ __('messages.attendance_ui.from') }} <input type="datetime-local" wire:model="coverage.from" class="rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label>{{ __('messages.attendance_ui.to') }} <input type="datetime-local" wire:model="coverage.to" class="rounded-xl border border-gray-200 p-2" dir="ltr"></label></div>
                @foreach ($this->branches() as $id => $name)
                    <label class="flex items-center gap-2" wire:key="attendance-branch-{{ $id }}"><input type="checkbox" wire:model="coverage.branch_ids" value="{{ $id }}">{{ $name }}
                        @if (in_array($id, $this->unconfirmableBranches())) <span class="text-amber-700">{{ __('messages.attendance_ui.not_confirmable') }}</span> @endif
                    </label>
                @endforeach
                @error('coverage') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="preview">{{ __('messages.attendance_ui.preview') }}</x-filament::button>
            </section>
        @endif

        @if ($step === 'preview' && ($preview = $this->previewData()))
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.preview') }}</h2>
                <p>{{ __('messages.attendance_ui.counts', $preview['counts']) }}</p>
                @if ($preview['already_imported']) <p class="text-amber-700">{{ __('messages.attendance_import.already_imported') }}</p> @endif
                <h3 class="font-semibold">{{ __('messages.attendance_ui.devices') }}</h3>
                @foreach ($preview['per_device'] as $serial => $count) <p><bdi dir="ltr">{{ $serial }}</bdi>: {{ $count }}</p> @endforeach
                <h3 class="font-semibold">{{ __('messages.attendance_ui.per_day') }}</h3>
                @foreach ($preview['per_day'] as $day => $count) <p><bdi dir="ltr">{{ $day }}</bdi>: {{ $count }}</p> @endforeach
                <p>{{ __('messages.attendance_ui.new_devices') }}: {{ $preview['counts']['new_devices'] }}</p>
                @foreach ($preview['samples']['devices'] as $device) <p>{{ __('messages.attendance_ui.pending_device') }}: <bdi dir="ltr">{{ $device['serial'] }}</bdi> — {{ $device['name'] }}</p> @endforeach
                <h3 class="font-semibold">{{ __('messages.attendance_ui.unknown') }}</h3>
                <p>{{ __('messages.attendance_ui.non_employees') }}: {{ $preview['counts']['non_employee_ids'] }} {{ __('messages.attendance_ui.sample_only') }}</p>
                @foreach ($preview['samples']['non_employee'] as $sample) <p><bdi dir="ltr">{{ $sample['code'] ?? '' }}</bdi></p> @endforeach
                <p>{{ __('messages.attendance_ui.data_issues') }}: {{ $preview['counts']['unknown_ids'] }} {{ __('messages.attendance_ui.sample_only') }}</p>
                @foreach ($preview['samples']['unknown'] as $sample) <p><bdi dir="ltr">{{ $sample['code'] ?? '' }}</bdi></p> @endforeach
                @foreach ($preview['samples']['errors'] as $sample) <p class="text-red-600">{{ __('messages.attendance_ui.row_error', ['row' => $sample['row'], 'codes' => implode(', ', array_map(fn (string $code): string => __('messages.attendance_ui.row_errors.'.$code), $sample['codes']))]) }}</p> @endforeach
                @if ($preview['token']) <x-filament::button wire:click="commit">{{ __('messages.attendance_ui.commit') }}</x-filament::button> @endif
            </section>
        @endif

        @if ($step === 'progress' && ($progress = $this->progress()))
            <section wire:poll.3s class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.progress') }} <bdi dir="ltr">#{{ $progress['batch']->id }}</bdi> · <bdi dir="ltr">{{ $progress['batch']->file_name }}</bdi></h2>
                @foreach ($progress['events'] as $event)
                    <p wire:key="attendance-event-{{ $event->id }}"><bdi dir="ltr">{{ $event->created_at->format('Y-m-d H:i:s') }}</bdi> — {{ __('messages.attendance_ui.states.'.$event->state) }}
                        @if ($event->counts) @foreach ($event->counts as $key => $count) <span>{{ __('messages.attendance_ui.count_labels.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span> @endforeach @endif
                        @if ($event->error_code) {{ __('messages.attendance_ui.failures.'.$event->error_code) }} @endif
                    </p>
                @endforeach
            </section>
        @endif
    </div>
</x-filament-panels::page>
