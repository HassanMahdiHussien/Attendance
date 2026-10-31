<x-filament-panels::page>
    <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadCenter::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.back') }}</a>
    <div class="space-y-6">
        <x-ui.card :title="__('messages.leave_ui.source_file')">
            <form wire:submit="prepare" class="space-y-3">
                <input type="file" name="sourceFile" wire:model="sourceFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block w-full rounded-xl border border-gray-200 p-2">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile">{{ __('messages.leave_ui.continue') }}</x-filament::button>
            </form>
        </x-ui.card>

        @if ($step === 'mapping')
            <x-ui.card :title="__('messages.leave_ui.mapping')">
                <p>{{ __('messages.leave_ui.mapping_help') }}</p>
                <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
                    @foreach (['column', 'header', 'field'] as $heading)<th class="p-2">{{ __('messages.leave_ui.'.$heading) }}</th>@endforeach
                </tr></thead><tbody>
                    @foreach ($headers as $index => $header)
                        <tr class="border-t" wire:key="leave-mapping-{{ $index }}"><td class="p-2" dir="ltr">{{ $index + 1 }}</td><td class="p-2">{{ $header ?: __('messages.leave_ui.blank_header') }}</td>
                            <td class="p-2"><select wire:model.live="mapping.{{ $index }}" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.leave_ui.unmapped') }}</option>
                                @foreach (\App\Services\Leave\LeaveFile::FIELDS as $field)<option value="{{ $field }}">{{ __('messages.leave_ui.fields.'.$field) }}</option>@endforeach
                            </select></td></tr>
                    @endforeach
                </tbody></table></div>
                @error('mapping') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="setScopeStep">{{ __('messages.leave_ui.continue') }}</x-filament::button>
            </x-ui.card>
        @endif

        @if (in_array($step, ['scope', 'preview'], true))
            <x-ui.card :title="__('messages.leave_ui.scope_title')">
                <div class="flex flex-wrap gap-4">
                    <label>{{ __('messages.leave_ui.scope') }} <select wire:model="scope.scope" class="rounded-xl border border-gray-200 p-2"><option value="partial">{{ __('messages.leave_ui.partial') }}</option><option value="full">{{ __('messages.leave_ui.full') }}</option></select></label>
                    <label>{{ __('messages.leave_ui.snapshot_at') }} <input type="text" wire:model="scope.snapshot_at" placeholder="{{ __('messages.leave_ui.snapshot_format') }}" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.period_from') }} <input type="date" wire:model="scope.period_from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_ui.period_to') }} <input type="date" wire:model="scope.period_to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                </div>
                <p class="text-sm text-gray-600">{{ __('messages.leave_ui.full_scope_help') }}</p>
                @error('scope') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="preview">{{ __('messages.leave_ui.preview') }}</x-filament::button>
            </x-ui.card>
        @endif

        @if ($step === 'preview' && ($preview = $this->previewData()))
            <x-ui.card :title="__('messages.leave_ui.preview')">
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($preview['counts'] as $key => $count)
                        <p>{{ __('messages.leave_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></p>
                    @endforeach
                </div>
                <h3 class="mt-3 font-semibold">{{ __('messages.leave_ui.status') }}</h3>
                @foreach ($preview['status_counts'] as $status => $count)<p>{{ __('messages.leave_ui.statuses.'.$status) }}: <bdi dir="ltr">{{ $count }}</bdi></p>@endforeach
                <h3 class="mt-3 font-semibold">{{ __('messages.leave_ui.type') }}</h3>
                @foreach ($preview['type_counts'] as $type => $count)<p>{{ $type }}: <bdi dir="ltr">{{ $count }}</bdi></p>@endforeach
                @if ($preview['other_type_count'])<p>{{ __('messages.leave_ui.other_types') }}: <bdi dir="ltr">{{ $preview['other_type_count'] }}</bdi></p>@endif
                @if ($preview['unmapped_type_counts'] !== [])
                    <h3 class="mt-3 font-semibold">{{ __('messages.leave_ui.counts.unmapped_types') }}</h3>
                    @foreach ($preview['unmapped_type_counts'] as $type => $count)<p>{{ $type }}: <bdi dir="ltr">{{ $count }}</bdi></p>@endforeach
                @endif
                @if ($preview['already_imported']) <p class="text-amber-700">{{ __('messages.leave_import.already_imported') }}</p> @endif
                @foreach ($preview['samples'] as $kind => $samples)
                    @if ($samples !== [])<h3 class="mt-3 font-semibold">{{ __('messages.leave_ui.samples.'.$kind) }}</h3>@endif
                    @foreach ($samples as $sample)
                        <p>{{ __('messages.leave_ui.row') }} <bdi dir="ltr">{{ $sample['row'] }}</bdi>
                            @foreach ($sample['codes'] ?? [] as $code) {{ $this->errorLabel($code) }} @endforeach
                        </p>
                    @endforeach
                @endforeach
                @if ($preview['token']) <x-filament::button wire:click="commit">{{ __('messages.leave_ui.commit') }}</x-filament::button> @endif
            </x-ui.card>
        @endif

        @if ($step === 'progress' && ($progress = $this->progress()))
            <x-ui.card :title="__('messages.leave_ui.progress')">
                <div wire:poll.3s>
                    <p><bdi dir="ltr">#{{ $progress['batch']->id }} · {{ $progress['batch']->file_name }}</bdi></p>
                    @foreach ($progress['events'] as $event)
                        <p wire:key="leave-event-{{ $event->id }}"><bdi dir="ltr">{{ $event->created_at->format('Y-m-d H:i:s') }}</bdi> — {{ __('messages.leave_ui.states.'.$event->state) }}
                            @foreach ($event->counts ?? [] as $key => $count) {{ __('messages.leave_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi> @endforeach
                            @if ($event->error_code) {{ __('messages.leave_ui.failures.'.$event->error_code) }} @endif
                        </p>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
