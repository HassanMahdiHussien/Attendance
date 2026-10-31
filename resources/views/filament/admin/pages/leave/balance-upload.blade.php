<x-filament-panels::page>
    <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadCenter::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.upload_center.back') }}</a>
    <div class="space-y-6">
        <x-ui.card :title="__('messages.leave_balance_ui.source_file')">
            <form wire:submit="prepare" class="space-y-3">
                <input type="file" name="sourceFile" wire:model="sourceFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block w-full rounded-xl border border-gray-200 p-2">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sourceFile">{{ __('messages.leave_balance_ui.continue') }}</x-filament::button>
            </form>
        </x-ui.card>

        @if (in_array($step, ['mapping', 'preview'], true))
            <x-ui.card :title="__('messages.leave_balance_ui.mapping')">
                <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
                    @foreach (['column', 'header', 'subheader', 'field'] as $heading)<th class="p-2">{{ __('messages.leave_balance_ui.'.$heading) }}</th>@endforeach
                </tr></thead><tbody>
                    @foreach ($headers as $index => $header)
                        <tr class="border-t" wire:key="balance-mapping-{{ $index }}">
                            <td class="p-2" dir="ltr">{{ $index + 1 }}</td><td class="p-2">{{ $header }}</td><td class="p-2">{{ $subheaders[$index] ?? '' }}</td>
                            <td class="p-2"><select wire:model.live="mapping.{{ $index }}" class="rounded-xl border border-gray-200 p-2">
                                <option value="">{{ __('messages.leave_balance_ui.unmapped') }}</option>
                                @foreach (\App\Services\Leave\LeaveBalanceFile::FIELDS as $field)
                                    <option value="{{ $field }}">{{ __('messages.leave_balance_ui.fields.'.$field) }}</option>
                                @endforeach
                            </select></td>
                        </tr>
                    @endforeach
                </tbody></table></div>
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
                @if ($preview['already_imported'])<p class="text-amber-700">{{ __('messages.leave_balance_ui.already_imported') }}</p>@endif
                @if ($preview['token'])<x-filament::button wire:click="commit">{{ __('messages.leave_balance_ui.commit') }}</x-filament::button>@endif
            </x-ui.card>
        @endif

        @if ($step === 'progress' && ($progress = $this->progress()))
            <x-ui.card :title="__('messages.leave_balance_ui.progress')">
                <div wire:poll.3s>
                    <p><bdi dir="ltr">#{{ $progress['batch']->id }} · {{ $progress['batch']->file_name }}</bdi></p>
                    @foreach ($progress['events'] as $event)
                        <p wire:key="balance-event-{{ $event->id }}">{{ __('messages.leave_balance_ui.states.'.$event->state) }}
                            @foreach ($event->counts ?? [] as $key => $count) {{ __('messages.leave_balance_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi> @endforeach
                            @if ($event->error_code) {{ __('messages.leave_balance_ui.failures.'.$event->error_code) }} @endif
                        </p>
                    @endforeach
                </div>
                <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Leave\LeaveBalanceBatches::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.leave_balance_ui.uploads') }}</a>
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
