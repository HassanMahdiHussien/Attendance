<x-filament-panels::page>
    <section wire:poll.5s class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <table class="w-full text-start text-sm">
            <thead><tr>
                @foreach (['report', 'generated_by', 'generated_at', 'period', 'rows', 'state', 'actions'] as $column)
                    <th class="p-2">{{ __('messages.export_ui.'.$column) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($this->exports() as $export)
                    @php $event = $this->latestEvent($export); @endphp
                    <tr class="border-t" wire:key="attendance-export-{{ $export->id }}">
                        <td class="p-2">{{ __('messages.export_ui.kinds.'.$export->kind) }}</td>
                        <td class="p-2">{{ $export->requester?->name ?? __('messages.export_ui.system') }}</td>
                        <td class="p-2" dir="ltr">{{ $export->created_at->format('Y-m-d H:i') }}</td>
                        <td class="p-2" dir="ltr">{{ $export->filters['from'] }} — {{ $export->filters['to'] }}</td>
                        <td class="p-2" dir="ltr">{{ $export->row_count }}</td>
                        <td class="p-2">{{ __('messages.export_ui.states.'.($event?->state ?? 'queued')) }}
                            @if ($event?->error_code) — {{ __('messages.export_ui.errors.'.$event->error_code) }} @endif
                        </td>
                        <td class="p-2">
                            @if ($event?->state === 'completed' && $export->file_path)
                                <a class="ui-link" href="{{ route('attendance.exports.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'export' => $export->id]) }}">{{ __('messages.export_ui.download') }}</a>
                            @endif
                            @if ($event?->state === 'failed')
                                <button type="button" class="ui-link" wire:click="retry({{ $export->id }})">{{ __('messages.export_ui.retry') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $this->exports()->links() }}
    </section>
</x-filament-panels::page>
