<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.leave_batches') }}</p>
    <x-ui.card :title="__('messages.leave_ui.batches_title')">
        <div wire:poll.5s class="overflow-x-auto"><x-ui.table><thead><tr>
            @foreach (['file', 'uploader', 'time', 'scope', 'snapshot_at', 'counts_label', 'state', 'actions'] as $column)<th class="p-2">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="leave-batch-{{ $batch->id }}">
                    <x-ui.td :label="__('messages.leave_ui.file')"><bdi dir="ltr">{{ $batch->file_name }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.uploader')">{{ \App\Models\User::find($batch->uploaded_by)?->name }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.time')">{{ $batch->created_at->format('Y-m-d H:i') }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.scope')">{{ __('messages.leave_ui.'.$batch->scope) }} <bdi dir="ltr">{{ $batch->period_from?->toDateString() }} – {{ $batch->period_to?->toDateString() }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.snapshot_at')">{{ $batch->snapshot_at->format('Y-m-d H:i:s') }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.counts_label')">@foreach ($event?->counts ?? [] as $key => $count)<span class="block">{{ __('messages.leave_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span>@endforeach</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.state')"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone($event?->state)">{{ __('messages.leave_ui.states.'.($event?->state ?? 'queued')) }}</x-ui.badge> @if ($event?->error_code) {{ __('messages.leave_ui.failures.'.$event->error_code) }} @endif</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.actions')"><a class="underline" href="{{ route('leave.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'leaveBatch' => $batch->id]) }}">{{ __('messages.leave_ui.download') }}</a>
                        @if ($event?->state === 'failed') <button class="ms-2 underline" wire:click="retry({{ $batch->id }})">{{ __('messages.leave_ui.retry') }}</button> @endif
                    </x-ui.td>
                </tr>
            @empty <tr><td colspan="8" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table></div>
        {{ $this->batches()->links() }}
    </x-ui.card>
</x-filament-panels::page>
