<x-filament-panels::page>
    <div class="space-y-4">
        <section class="flex flex-wrap gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <label class="min-w-0 max-w-full">{{ __('messages.attendance_ui.type') }} <select wire:model.live="type" class="block w-full max-w-full rounded-xl border border-gray-200 p-2 sm:w-auto"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach (['unknown_person', 'device_pending', 'import_row_error', 'pattern_review'] as $type)<option value="{{ $type }}">{{ __('messages.attendance_import.issue_types.'.$type) }}</option>@endforeach</select></label>
            <label class="min-w-0 max-w-full">{{ __('messages.attendance_ui.state') }} <select wire:model.live="status" class="block w-full max-w-full rounded-xl border border-gray-200 p-2 sm:w-auto"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach (['open', 'resolved', 'dismissed'] as $state)<option value="{{ $state }}">{{ __('messages.attendance_ui.issue_states.'.$state) }}</option>@endforeach</select></label>
        </section>
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            @foreach ($this->issues() as $issue)
                <div class="border-b p-2" wire:key="attendance-issue-{{ $issue->id }}">
                    <strong>{{ __('messages.attendance_import.issue_types.'.$issue->type) }}</strong> <bdi dir="ltr">{{ $issue->subject_key }}</bdi> — {{ __('messages.attendance_ui.issue_states.'.$issue->status) }}
                    @if ($issue->status === 'open')
                        <div class="mt-2 flex flex-wrap gap-2"><input type="text" wire:model="notes.{{ $issue->id }}" class="rounded-xl border border-gray-200 p-2" placeholder="{{ __('messages.attendance_ui.note') }}">
                            @if ($issue->type !== 'pattern_review')
                                <button class="underline" wire:click="close({{ $issue->id }}, &quot;resolve&quot;)">{{ __('messages.attendance_ui.resolve') }}</button>
                            @endif
                            <button class="underline" wire:click="close({{ $issue->id }}, &quot;dismiss&quot;)">{{ __('messages.attendance_ui.dismiss') }}</button></div>
                        @error('notes.'.$issue->id) <p class="text-red-600">{{ $message }}</p> @enderror
                    @else <p>{{ $issue->note }}</p> @endif
                </div>
            @endforeach
            {{ $this->issues()->links() }}
        </section>
    </div>
</x-filament-panels::page>
