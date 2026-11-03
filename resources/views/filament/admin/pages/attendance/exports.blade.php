<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.exports') }}</p>
    <section wire:poll.5s class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <x-ui.table>
            <thead><tr>
                @foreach (['report', 'generated_by', 'generated_at', 'period', 'rows', 'state', 'actions'] as $column)
                    <th class="p-2">{{ __('messages.export_ui.'.$column) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse ($this->exports() as $export)
                    @php $event = $this->latestEvent($export); @endphp
                    <tr class="border-t" wire:key="attendance-export-{{ $export->id }}">
                        <x-ui.td :label="__('messages.export_ui.report')">{{ __('messages.export_ui.kinds.'.$export->kind) }}</x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.generated_by')">{{ $export->requester?->name ?? __('messages.export_ui.system') }}</x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.generated_at')">{{ $export->created_at->format('Y-m-d H:i') }}</x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.period')">{{ $export->filters['from'] }} — {{ $export->filters['to'] }}</x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.rows')">{{ $export->row_count }}</x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.state')"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone($event?->state)">{{ __('messages.export_ui.states.'.($event?->state ?? 'queued')) }}</x-ui.badge>
                            @if ($event?->error_code) — {{ __('messages.export_ui.errors.'.$event->error_code) }} @endif
                        </x-ui.td>
                        <x-ui.td :label="__('messages.export_ui.actions')">
                            @if ($event?->state === 'completed' && $export->file_path)
                                <a class="ui-link" href="{{ route('attendance.exports.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'export' => $export->id]) }}">{{ __('messages.export_ui.download') }}</a>
                            @endif
                            @if ($event?->state === 'failed')
                                <button type="button" class="ui-link" wire:click="retry({{ $export->id }})">{{ __('messages.export_ui.retry') }}</button>
                            @endif
                        </x-ui.td>
                    </tr>
                @empty <tr><td colspan="7" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody>
        </x-ui.table>
        {{ $this->exports()->links() }}
    </section>
</x-filament-panels::page>
