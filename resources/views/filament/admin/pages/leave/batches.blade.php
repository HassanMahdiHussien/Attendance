<x-filament-panels::page>
    <x-ui.card :title="__('messages.leave_ui.batches_title')">
        <div wire:poll.5s class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
            @foreach (['file', 'uploader', 'time', 'scope', 'snapshot_at', 'counts_label', 'state', 'actions'] as $column)<th class="p-2">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @foreach ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="leave-batch-{{ $batch->id }}">
                    <td class="p-2"><bdi dir="ltr">{{ $batch->file_name }}</bdi></td>
                    <td class="p-2">{{ \App\Models\User::find($batch->uploaded_by)?->name }}</td>
                    <td class="p-2" dir="ltr">{{ $batch->created_at->format('Y-m-d H:i') }}</td>
                    <td class="p-2">{{ __('messages.leave_ui.'.$batch->scope) }} <bdi dir="ltr">{{ $batch->period_from?->toDateString() }} – {{ $batch->period_to?->toDateString() }}</bdi></td>
                    <td class="p-2" dir="ltr">{{ $batch->snapshot_at->format('Y-m-d H:i:s') }}</td>
                    <td class="p-2">@foreach ($event?->counts ?? [] as $key => $count)<span class="block">{{ __('messages.leave_ui.counts.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span>@endforeach</td>
                    <td class="p-2">{{ __('messages.leave_ui.states.'.($event?->state ?? 'queued')) }} @if ($event?->error_code) {{ __('messages.leave_ui.failures.'.$event->error_code) }} @endif</td>
                    <td class="p-2"><a class="underline" href="{{ route('leave.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'leaveBatch' => $batch->id]) }}">{{ __('messages.leave_ui.download') }}</a>
                        @if ($event?->state === 'failed') <button class="ms-2 underline" wire:click="retry({{ $batch->id }})">{{ __('messages.leave_ui.retry') }}</button> @endif
                    </td>
                </tr>
            @endforeach
        </tbody></table></div>
        {{ $this->batches()->links() }}
    </x-ui.card>
</x-filament-panels::page>
