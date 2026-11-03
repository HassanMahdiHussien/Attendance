<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.balance_batches') }}</p>
    <x-ui.card :title="__('messages.leave_balance_ui.uploads')">
        <div wire:poll.5s class="overflow-x-auto"><x-ui.table><thead><tr>
            @foreach (['balance_date', 'file', 'uploader', 'time', 'state', 'counts_label', 'actions'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="balance-batch-{{ $batch->id }}">
                    <x-ui.td :label="__('messages.leave_balance_ui.balance_date')">{{ $batch->balance_date->toDateString() }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.file')"><bdi dir="ltr">{{ $batch->file_name }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.uploader')">{{ \App\Models\User::find($batch->uploaded_by)?->name }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.time')">{{ $batch->created_at->format('Y-m-d H:i') }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.state')"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone($event?->state)">{{ __('messages.leave_balance_ui.states.'.($event?->state ?? 'queued')) }}</x-ui.badge> @if ($event?->error_code) {{ __('messages.leave_balance_ui.failures.'.$event->error_code) }} @endif</x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.counts_label')">@foreach ($event?->counts ?? [] as $key => $count)<span class="block">{{ __('messages.leave_balance_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span>@endforeach</x-ui.td>
                    <x-ui.td :label="__('messages.leave_balance_ui.actions')"><button class="ui-link" wire:click="showBatch({{ $batch->id }})">{{ __('messages.leave_balance_ui.details') }}</button>
                        <a class="ui-link ms-2" href="{{ route('leave.balance.file', ['company' => $this->company()->id, 'balanceBatch' => $batch->id]) }}">{{ __('messages.leave_balance_ui.download') }}</a>
                    </x-ui.td>
                </tr>
            @empty <tr><td colspan="7" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table></div>
        {{ $this->batches()->links() }}
    </x-ui.card>
    @if ($batch = $this->selectedBatch())
        <x-ui.card :title="__('messages.leave_balance_ui.skipped_rows')">
            <p><bdi dir="ltr">{{ $batch->file_name }}</bdi> · <bdi dir="ltr">{{ $batch->balance_date->toDateString() }}</bdi></p>
            <div class="overflow-x-auto"><x-ui.table><thead><tr>
                @foreach (['row', 'employee_code', 'outcome', 'reason'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
            </tr></thead><tbody>
                @forelse ($this->skippedRows() as $row)
                    <tr class="border-t" wire:key="balance-row-{{ $row->id }}">
                        <x-ui.td :label="__('messages.leave_balance_ui.row')">{{ $row->row_number }}</x-ui.td><x-ui.td :label="__('messages.leave_balance_ui.employee_code')"><bdi dir="ltr">{{ $row->employee_code }}</bdi></x-ui.td>
                        <x-ui.td :label="__('messages.leave_balance_ui.outcome')">{{ __('messages.leave_balance_ui.outcomes.'.$row->outcome) }}</x-ui.td>
                        <x-ui.td :label="__('messages.leave_balance_ui.reason')">{{ $row->reason_code ? __('messages.leave_balance.reasons.'.$row->reason_code) : '—' }}</x-ui.td>
                    </tr>
                @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table></div>
            {{ $this->skippedRows()->links() }}
        </x-ui.card>
    @endif
</x-filament-panels::page>
