<x-filament-panels::page>
    <x-ui.card :title="__('messages.leave_balance_ui.uploads')">
        <div wire:poll.5s class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
            @foreach (['balance_date', 'file', 'uploader', 'time', 'state', 'counts_label', 'actions'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @foreach ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="balance-batch-{{ $batch->id }}">
                    <td class="p-2" dir="ltr">{{ $batch->balance_date->toDateString() }}</td>
                    <td class="p-2"><bdi dir="ltr">{{ $batch->file_name }}</bdi></td>
                    <td class="p-2">{{ \App\Models\User::find($batch->uploaded_by)?->name }}</td>
                    <td class="p-2" dir="ltr">{{ $batch->created_at->format('Y-m-d H:i') }}</td>
                    <td class="p-2">{{ __('messages.leave_balance_ui.states.'.($event?->state ?? 'queued')) }} @if ($event?->error_code) {{ __('messages.leave_balance_ui.failures.'.$event->error_code) }} @endif</td>
                    <td class="p-2">@foreach ($event?->counts ?? [] as $key => $count)<span class="block">{{ __('messages.leave_balance_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span>@endforeach</td>
                    <td class="p-2"><button class="ui-link" wire:click="showBatch({{ $batch->id }})">{{ __('messages.leave_balance_ui.details') }}</button>
                        <a class="ui-link ms-2" href="{{ route('leave.balance.file', ['company' => $this->company()->id, 'balanceBatch' => $batch->id]) }}">{{ __('messages.leave_balance_ui.download') }}</a>
                    </td>
                </tr>
            @endforeach
        </tbody></table></div>
        {{ $this->batches()->links() }}
    </x-ui.card>
    @if ($batch = $this->selectedBatch())
        <x-ui.card :title="__('messages.leave_balance_ui.skipped_rows')">
            <p><bdi dir="ltr">{{ $batch->file_name }}</bdi> · <bdi dir="ltr">{{ $batch->balance_date->toDateString() }}</bdi></p>
            <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
                @foreach (['row', 'employee_code', 'outcome', 'reason'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
            </tr></thead><tbody>
                @foreach ($this->skippedRows() as $row)
                    <tr class="border-t" wire:key="balance-row-{{ $row->id }}">
                        <td class="p-2" dir="ltr">{{ $row->row_number }}</td><td class="p-2"><bdi dir="ltr">{{ $row->employee_code }}</bdi></td>
                        <td class="p-2">{{ __('messages.leave_balance_ui.outcomes.'.$row->outcome) }}</td>
                        <td class="p-2">{{ $row->reason_code ? __('messages.leave_balance.reasons.'.$row->reason_code) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            {{ $this->skippedRows()->links() }}
        </x-ui.card>
    @endif
</x-filament-panels::page>
