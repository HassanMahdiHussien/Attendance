<x-filament-panels::page>
    <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadCenter::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.back') }}</a>
    <p class="text-sm text-gray-600">{{ __('messages.clear_screens.upload_context') }}</p>
    <div class="space-y-6">
        <x-ui.card :title="__('messages.leave_balance_ui.source_file')">
            <x-ui.upload-form wire:submit="prepare" class="space-y-3">
                <input type="file" name="sourceFile" wire:model="sourceFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block w-full rounded-xl border border-gray-200 p-2">
                @error('sourceFile') <p class="text-red-600" x-show="! stale">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile" x-bind:disabled="uploading">{{ __('messages.leave_balance_ui.continue') }}</x-filament::button>
            </x-ui.upload-form>
        </x-ui.card>

        @if (in_array($step, ['mapping', 'preview'], true))
            <x-ui.card :title="__('messages.leave_balance_ui.mapping')">
                <x-ui.table><thead><tr>
                    @foreach (['column', 'header', 'subheader', 'field'] as $heading)<th>{{ __('messages.leave_balance_ui.'.$heading) }}</th>@endforeach
                </tr></thead><tbody>
                    @foreach ($headers as $index => $header)
                        <tr class="border-t" wire:key="balance-mapping-{{ $index }}">
                            <x-ui.td :label="__('messages.leave_balance_ui.column')"><bdi dir="ltr">{{ $index + 1 }}</bdi></x-ui.td><x-ui.td :label="__('messages.leave_balance_ui.header')">{{ $header ?: '—' }}</x-ui.td><x-ui.td :label="__('messages.leave_balance_ui.subheader')">{{ ($subheaders[$index] ?? null) ?: '—' }}</x-ui.td>
                            <x-ui.td :label="__('messages.leave_balance_ui.field')"><select wire:model.live="mapping.{{ $index }}" class="max-w-full rounded-xl border border-gray-200 p-2">
                                <option value="">{{ __('messages.leave_balance_ui.unmapped') }}</option>
                                @foreach (\App\Services\Leave\LeaveBalanceFile::FIELDS as $field)
                                    <option value="{{ $field }}">{{ __('messages.leave_balance_ui.fields.'.$field) }}</option>
                                @endforeach
                            </select></x-ui.td>
                        </tr>
                    @endforeach
                </tbody></x-ui.table>
                @error('mapping') <p class="text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-4 pt-3">
                    <label>{{ __('messages.leave_balance_ui.balance_date') }} <input type="date" max="{{ today('Asia/Baghdad')->toDateString() }}" wire:model="balanceDate" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    <label>{{ __('messages.leave_balance_ui.balance_year') }} <input type="number" min="2000" max="2100" wire:model="balanceYear" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                </div>
                @error('balanceDate') <p class="text-red-600">{{ $message }}</p> @enderror
                @error('balanceYear') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button wire:click="preview">{{ __('messages.leave_balance_ui.preview') }}</x-filament::button>
            </x-ui.card>
        @endif

        @if ($step === 'preview' && ($preview = $this->previewData()))
            <x-ui.card :title="__('messages.leave_balance_ui.preview')">
                @if ($preview['token'])<p><x-ui.badge :tone="collect($preview['samples'])->flatten(1)->contains(fn (array $sample): bool => ($sample['reasons'] ?? []) !== []) ? 'amber' : 'green'">{{ __('messages.clear_screens.upload_ready') }}</x-ui.badge></p>@endif
                @foreach ($preview['samples'] as $samples)
                    @foreach ($samples as $sample)
                        @if ($sample['reasons'] ?? []) <p class="text-red-600">{{ __('messages.leave_balance_ui.row') }} <bdi dir="ltr">{{ $sample['row'] }}</bdi> · <bdi dir="ltr">{{ $sample['code'] }}</bdi> @foreach ($sample['reasons'] as $reason) {{ __('messages.leave_balance.reasons.'.$reason) }} @endforeach</p> @endif
                    @endforeach
                @endforeach
                @if ($preview['already_imported'])<p class="text-amber-700">{{ __('messages.leave_balance_ui.already_imported') }}</p>@endif
                @if ($preview['token'])<x-filament::button wire:click="confirmImport">{{ __('messages.leave_balance_ui.commit') }}</x-filament::button>@endif
                <details wire:ignore.self><summary class="cursor-pointer font-semibold">{{ __('messages.upload_center.details') }}</summary>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($preview['counts'] as $key => $count)
                        <p>{{ __('messages.leave_balance_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></p>
                    @endforeach
                </div>
                @foreach ($preview['samples'] as $kind => $samples)
                    @if ($samples !== [])<h3 class="mt-3 font-semibold">{{ __('messages.leave_balance_ui.counts.'.$kind) }}</h3>@endif
                    @foreach ($samples as $sample)
                        <p>{{ __('messages.leave_balance_ui.row') }} <bdi dir="ltr">{{ $sample['row'] }}</bdi> · <bdi dir="ltr">{{ $sample['code'] }}</bdi>
                            @foreach ($sample['reasons'] ?? [] as $reason) {{ __('messages.leave_balance.reasons.'.$reason) }} @endforeach
                        </p>
                    @endforeach
                @endforeach
                </details>
            </x-ui.card>
        @endif

        @if ($step === 'progress' && ($progress = $this->progress()))
            <x-ui.card :title="__('messages.leave_balance_ui.progress')">
                <div wire:poll.3s>
                    <p><bdi dir="ltr">#{{ $progress['batch']->id }} · {{ $progress['batch']->file_name }}</bdi></p>
                    @foreach ($progress['events'] as $event)
                        <p wire:key="balance-event-{{ $event->id }}"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone($event->state)">{{ __('messages.leave_balance_ui.states.'.$event->state) }}</x-ui.badge>
                            @foreach ($event->counts ?? [] as $key => $count) {{ __('messages.leave_balance_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi> @endforeach
                            @if ($event->error_code) {{ __('messages.leave_balance_ui.failures.'.$event->error_code) }} {{ __('messages.clear_screens.upload_retry') }} @endif
                        </p>
                    @endforeach
                </div>
                <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveBalanceBatches::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.leave_balance_ui.uploads') }}</a>
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
