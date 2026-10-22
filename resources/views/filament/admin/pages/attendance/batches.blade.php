<x-filament-panels::page>
    <section wire:poll.5s class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <table class="w-full text-start"><thead><tr>
            @foreach (['file', 'uploader', 'time', 'coverage', 'counts_label', 'state', 'actions'] as $column)<th class="p-2">{{ __('messages.attendance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @foreach ($this->batches() as $batch)
                @php $event = $this->latestEvent($batch); @endphp
                <tr class="border-t" wire:key="attendance-batch-{{ $batch->id }}">
                    <td class="p-2">{{ $batch->file_name }}</td>
                    <td class="p-2">{{ \App\Models\User::find($batch->uploaded_by)?->name }}</td>
                    <td class="p-2" dir="ltr">{{ $batch->created_at->format('Y-m-d H:i') }}</td>
                    <td class="p-2"><bdi dir="ltr">{{ $batch->coverage_from?->format('Y-m-d H:i') }} – {{ $batch->coverage_to?->format('Y-m-d H:i') }}</bdi><br>{{ implode(', ', \App\Models\Branch::query()->whereIn('id', $batch->coverage_branch_ids)->pluck('name')->all()) }}</td>
                    <td class="p-2">@if ($event?->counts) @foreach ($event->counts as $key => $count) <span class="block">{{ __('messages.attendance_ui.count_labels.'.$key) }}: <bdi dir="ltr">{{ $count }}</bdi></span> @endforeach @else — @endif</td>
                    <td class="p-2">{{ __('messages.attendance_ui.states.'.($event?->state ?? 'queued')) }} @if ($event?->error_code) — {{ __('messages.attendance_ui.failures.'.$event->error_code) }} @endif</td>
                    <td class="p-2"><a class="underline" href="{{ route('attendance.import.file', ['company' => \Filament\Facades\Filament::getTenant()->id, 'attendanceBatch' => $batch->id]) }}">{{ __('messages.attendance_ui.download') }}</a>
                        @if (count($batch->summary['samples']['errors'] ?? []) > 0) <button class="ms-2 underline" wire:click="showErrors({{ $batch->id }})">{{ __('messages.attendance_ui.errors') }}</button> @endif
                        @if ($event?->state === 'failed') <button class="ms-2 underline" wire:click="retry({{ $batch->id }})">{{ __('messages.attendance_ui.retry') }}</button> @endif
                    </td>
                </tr>
            @endforeach
        </tbody></table>
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
