<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.attendance_batches') }}</p>
    <section wire:poll.5s class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <x-ui.table><thead><tr>
            @foreach (['file', 'uploader', 'time', 'coverage', 'counts_label', 'state', 'actions'] as $column)<th class="p-2">{{ __('messages.attendance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="attendance-batch-{{ $batch->id }}">
                    <x-ui.td :label="__('messages.attendance_ui.file')">{{ $batch->file_name }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.uploader')">{{ $this->uploaderName($batch) }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.time')">{{ $batch->created_at->format('Y-m-d H:i') }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.coverage')"><bdi dir="ltr">{{ $batch->coverage_from?->format('Y-m-d H:i') }} – {{ $batch->coverage_to?->format('Y-m-d H:i') }}</bdi><br>{{ $this->branchNames($batch) }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.counts_label')">@if ($event?->counts) @foreach ($event->counts as $key => $count) <span class="block">{{ __('messages.attendance_ui.count_labels.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span> @endforeach @else — @endif</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.state')"><x-ui.badge :tone="app(\App\Services\Attendance\Reporting\JobStatePresentation::class)->tone($event?->state)">{{ __('messages.attendance_ui.states.'.($event?->state ?? 'queued')) }}</x-ui.badge> @if ($event?->error_code) — {{ __('messages.attendance_ui.failures.'.$event->error_code) }} @endif</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.actions')"><a class="underline" href="{{ route('attendance.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'attendanceBatch' => $batch->id]) }}">{{ __('messages.attendance_ui.download') }}</a>
                        @if (count($batch->summary['samples']['errors'] ?? []) > 0) <button class="ms-2 underline" wire:click="showErrors({{ $batch->id }})">{{ __('messages.attendance_ui.errors') }}</button> @endif
                        @if ($event?->state === 'failed') <button class="ms-2 underline" wire:click="retry({{ $batch->id }})">{{ __('messages.attendance_ui.retry') }}</button> @endif
                    </x-ui.td>
                </tr>
            @empty <tr><td colspan="7" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table>
        {{ $this->batches()->links() }}
    </section>
    @if ($selectedBatch !== null)
        @php
            $samplePage = $this->errorSamples();
            $nextPage = min($samplePage['pages'], $errorPage + 1);
        @endphp
        <section class="mt-4 space-y-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold">{{ __('messages.attendance_ui.error_samples') }} <bdi dir="ltr">#{{ $selectedBatch }}</bdi></h2>
            @foreach ($samplePage['rows'] as $sample)<p>{{ __('messages.attendance_ui.row_error', ['row' => $sample['row'], 'codes' => implode(', ', array_map(fn (string $code): string => __('messages.attendance_ui.row_errors.'.$code), $sample['codes']))]) }}</p>@endforeach
            <div class="flex gap-3"><button class="underline" wire:click="$set(&quot;errorPage&quot;, {{ max(1, $errorPage - 1) }})" @disabled($errorPage <= 1)>{{ __('messages.attendance_ui.previous') }}</button>
                <span dir="ltr">{{ $errorPage }} / {{ max(1, $samplePage['pages']) }}</span>
                <button class="underline" wire:click="$set(&quot;errorPage&quot;, {{ $nextPage }})" @disabled($errorPage >= $samplePage['pages'])>{{ __('messages.attendance_ui.next') }}</button></div>
        </section>
    @endif
</x-filament-panels::page>
