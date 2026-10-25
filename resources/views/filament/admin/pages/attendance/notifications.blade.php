<x-filament-panels::page>
    <div class="space-y-5">
        @if ($this->canManage())
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">{{ __('messages.follow_up_ui.settings') }}</h2>
                <div class="flex flex-wrap gap-3">
                    <label>{{ __('messages.follow_up_ui.mode') }} <select wire:model.live="mode" class="rounded-xl border p-2"><option value="review">{{ __('messages.follow_up_ui.review') }}</option><option value="auto">{{ __('messages.follow_up_ui.auto') }}</option></select></label>
                    <label>{{ __('messages.follow_up_ui.build_time') }} <input type="time" wire:model="buildTime" dir="ltr" class="rounded-xl border p-2"></label>
                    <label>{{ __('messages.follow_up_ui.reminder_interval') }} <input type="number" min="1" wire:model="reminderIntervalDays" class="w-20 rounded-xl border p-2"></label>
                    <label>{{ __('messages.follow_up_ui.reminder_max') }} <input type="number" min="0" wire:model="reminderMax" class="w-20 rounded-xl border p-2"></label>
                    <label>{{ __('messages.follow_up_ui.language') }} <select wire:model="language" class="rounded-xl border p-2"><option value="en">{{ __('messages.follow_up_ui.english') }}</option><option value="ar">{{ __('messages.locale.arabic') }}</option></select></label>
                </div>
                @if ($mode === 'auto') <p class="text-amber-700">{{ __('messages.follow_up_ui.auto_notice') }}</p> @endif
                <label>{{ __('messages.follow_up_ui.reason') }} <input wire:model="reason" class="rounded-xl border p-2"></label>
                <button type="button" wire:click="saveSettings" class="rounded-xl bg-amber-600 px-4 py-2 text-white">{{ __('messages.follow_up_ui.save_settings') }}</button>
                @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
            </section>
            <button type="button" wire:click="build" class="rounded-xl border px-4 py-2">{{ __('messages.follow_up_ui.build_now') }}</button>
        @endif
        <section wire:poll.15s class="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.follow_up_ui.batches') }}</h2>
            @forelse ($this->batches() as $batch)
                <article wire:key="notification-batch-{{ $batch->id }}" class="space-y-2 border-b pb-4">
                    <p><bdi dir="ltr">{{ $batch->created_at }}</bdi> · {{ $batch->builder?->name ?? __('messages.export_ui.system') }} · {{ __('messages.follow_up_ui.batch_states.'.$batch->status) }} · {{ __('messages.follow_up_ui.'.$batch->mode) }} · {{ $batch->messages->count() }} {{ __('messages.follow_up_ui.messages') }}</p>
                    @if ($this->canManage() && $batch->status === 'draft')
                        <button type="button" wire:click="send({{ $batch->id }})" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.send_batch') }}</button>
                        <button type="button" wire:click="cancel({{ $batch->id }})" class="rounded-xl border px-3 py-1">{{ __('messages.follow_up_ui.cancel_batch') }}</button>
                    @endif
                    @foreach ($batch->messages as $message)
                        <div wire:key="notification-message-{{ $message->id }}" class="rounded-xl border p-3">
                            <p><bdi dir="ltr">{{ $message->address }}</bdi> · {{ __('messages.follow_up_ui.message_states.'.$message->status) }} · {{ $message->cases->count() }} {{ __('messages.follow_up_ui.cases') }}</p>
                            @if ($message->last_error) <p class="text-red-700">{{ __('messages.follow_up_ui.delivery_errors.'.$message->last_error) }}</p> @endif
                            <p>{{ __('messages.follow_up_ui.attempts') }}: {{ $message->attemptRows->count() }} @foreach ($message->attemptRows as $attempt) · {{ __('messages.follow_up_ui.attempt_results.'.$attempt->result) }} <bdi dir="ltr">{{ $attempt->started_at }}</bdi> @endforeach</p>
                            <button type="button" wire:click="preview({{ $message->id }})" class="underline">{{ __('messages.follow_up_ui.preview') }}</button>
                            @if ($this->canManage() && $message->status === 'draft')
                                <input wire:model="messageReasons.{{ $message->id }}" class="rounded-xl border p-2" placeholder="{{ __('messages.follow_up_ui.reason') }}"><button type="button" wire:click="exclude({{ $message->id }})" class="underline">{{ __('messages.follow_up_ui.exclude') }}</button>
                                @error('messageReasons.'.$message->id) <p class="text-red-600">{{ $message }}</p> @enderror
                            @endif
                            @if ($this->canManage() && $message->status === 'failed') <button type="button" wire:click="retry({{ $message->id }})" class="underline">{{ __('messages.follow_up_ui.retry') }}</button> @endif
                            @if ($this->canManage() && $message->status === 'uncertain')
                                <button type="button" wire:click="resolve({{ $message->id }}, 'resend')" class="underline">{{ __('messages.follow_up_ui.resend') }}</button>
                                <button type="button" wire:click="resolve({{ $message->id }}, 'mark_sent')" class="underline">{{ __('messages.follow_up_ui.mark_sent') }}</button>
                            @endif
                        </div>
                    @endforeach
                </article>
            @empty <p>{{ __('messages.follow_up_ui.none') }}</p> @endforelse
            {{ $this->batches()->links() }}
        </section>
        @if ($previewMessageId !== null)
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">{{ __('messages.follow_up_ui.preview') }}</h2><div class="overflow-auto">{!! $this->previewHtml() !!}</div></section>
        @endif
    </div>
</x-filament-panels::page>
