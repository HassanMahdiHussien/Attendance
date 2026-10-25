<div class="min-w-0" @if($pending) wire:poll.3s="poll" @endif>
    <x-ui.page-header :title="__('messages.chat_ui.title')" :subtitle="__('messages.chat_ui.subtitle')" />

    @if($company === null)
        <x-ui.card :title="__('messages.chat_ui.choose_company')">
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach($companies as $choice)
                    <button type="button" wire:click="chooseCompany({{ $choice->id }})" class="min-w-0 rounded-xl border border-line p-3 text-start hover:bg-primary-light">{{ $choice->name }}</button>
                @endforeach
            </div>
        </x-ui.card>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-600">
            <strong>{{ $company->name }}</strong>
            <span>·</span>
            <span>{{ $changesEnabled ? __('messages.chat_ui.changes_on') : __('messages.chat_ui.changes_off') }}</span>
            @if(auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin)
                <button type="button" wire:click="clearCompany" class="ui-link">{{ __('messages.chat_ui.change_company') }}</button>
            @endif
        </div>
        @unless($available)
            <div role="status" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">{{ __('messages.chat_ui.unconfigured') }}</div>
        @endunless

        <div class="grid min-w-0 gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <aside class="min-w-0 rounded-2xl border border-line bg-white p-3">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="font-bold">{{ __('messages.chat_ui.conversations') }}</h2>
                    <button type="button" wire:click="newConversation" @disabled(!$available) class="ui-link text-sm disabled:opacity-50">{{ __('messages.chat_ui.new') }}</button>
                </div>
                <div class="max-h-52 space-y-1 overflow-y-auto lg:max-h-[36rem]">
                    @forelse($conversations as $conversation)
                        <button type="button" wire:key="assistant-conversation-{{ $conversation->id }}" wire:click="open({{ $conversation->id }})" class="block w-full min-w-0 rounded-lg px-3 py-2 text-start text-sm {{ $conversationId === $conversation->id ? 'bg-primary-light font-semibold text-primary' : 'hover:bg-gray-50' }}">
                            <span class="block truncate">{{ $conversation->title ?: __('messages.chat_ui.conversation') }} <bdi dir="ltr">#{{ $conversation->id }}</bdi></span>
                            <span class="block text-xs font-normal text-gray-500"><bdi dir="ltr">{{ $conversation->last_message_at?->format('Y-m-d H:i') ?? $conversation->created_at?->format('Y-m-d H:i') }}</bdi></span>
                        </button>
                    @empty
                        <p class="p-2 text-sm text-gray-500">{{ __('messages.chat_ui.no_conversations') }}</p>
                    @endforelse
                </div>
            </aside>

            <section class="flex min-w-0 flex-col rounded-2xl border border-line bg-white" aria-label="{{ __('messages.chat_ui.messages') }}">
                <div class="min-h-64 min-w-0 space-y-5 p-4 sm:p-6">
                    @if($conversationId === null)
                        <p class="text-sm text-gray-500">{{ __('messages.chat_ui.prompt') }}</p>
                    @endif
                    @foreach($turns as $turn)
                        @foreach($messages->where('turn_id', $turn->id) as $message)
                            @if($message->role === 'user')
                                <div wire:key="assistant-message-{{ $message->id }}" class="ms-auto max-w-[95%] min-w-0 rounded-2xl bg-primary-light p-3 text-sm sm:max-w-[85%]">
                                    <p class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $message->content['text'] ?? '' }}</p>
                                </div>
                            @else
                                <div wire:key="assistant-message-{{ $message->id }}" class="max-w-[95%] min-w-0 space-y-3 text-sm sm:max-w-[85%]">
                                    <div class="rounded-2xl {{ $message->status === 'terminal' ? 'border border-amber-300 bg-amber-50' : 'border border-line bg-bg' }} p-3">
                                        @if($message->status === 'terminal')
                                            <strong class="mb-1 block">{{ $this->terminalLabel($turn->error_code) }}</strong>
                                        @endif
                                        <p class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $this->answer($message->content) }}</p>
                                    </div>
                                    @if($message->status === 'final')
                                        <div class="rounded-xl border-s-4 border-signal bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950">{{ $message->content['verified']['footer'] ?? __('messages.chat.verified_none') }}</div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                        @if($turn->toolCalls->isNotEmpty())
                            <details wire:key="assistant-tools-{{ $turn->id }}" class="max-w-full rounded-xl border border-line px-3 py-2 text-xs text-gray-600">
                                <summary class="cursor-pointer">{{ __('messages.chat_ui.tool_activity') }} ({{ $turn->toolCalls->count() }})</summary>
                                <ul class="mt-2 space-y-1">
                                    @foreach($turn->toolCalls as $call)
                                        <li>{{ __('messages.chat_ui.tool_names.'.$call->name) }} · {{ $call->is_error ? __('messages.chat_ui.error') : __('messages.chat_ui.ok') }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @foreach($cards[$turn->id] ?? [] as $proposalId => $card)
                            <article wire:key="assistant-proposal-{{ $proposalId }}" class="min-w-0 rounded-2xl border border-line p-4 text-sm" aria-label="{{ __('messages.chat_ui.proposal') }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="font-bold">{{ __('messages.chat_ui.proposal') }} <bdi dir="ltr">#{{ $card['id'] }}</bdi></h3>
                                    <span class="rounded-full bg-gray-100 px-2 py-1 text-xs">{{ __('messages.chat_ui.statuses.'.$card['status']) }}</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ __('messages.chat_ui.effective_from') }} <bdi dir="ltr">{{ $card['effective_from'] }}</bdi></p>
                                @foreach($card['employees'] as $entry)
                                    <div class="mt-3 min-w-0 border-t border-line pt-3">
                                        <strong>{{ $entry['name'] }} <bdi dir="ltr">({{ $entry['code'] }})</bdi></strong>
                                        <dl class="mt-2 space-y-1">
                                            @foreach($entry['fields'] as $field)
                                                <div class="grid min-w-0 gap-1 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                                    <dt class="text-gray-500">{{ $field['label'] }}</dt>
                                                    <dd class="min-w-0 break-words"><bdi>{{ $field['before'] }}</bdi> → <bdi>{{ $field['after'] }}</bdi></dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </div>
                                @endforeach
                                @if($card['status'] === 'proposed')
                                    <p class="mt-3 text-xs text-gray-600">{{ __('messages.chat_ui.recalculate_note') }}</p>
                                    @if($card['can_decide'])
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button type="button" wire:click="confirm({{ $proposalId }})" class="rounded-lg bg-signal px-3 py-2 font-semibold text-night">{{ __('messages.chat_ui.confirm') }}</button>
                                            <button type="button" wire:click="cancel({{ $proposalId }})" class="rounded-lg border border-line px-3 py-2">{{ __('messages.chat_ui.cancel') }}</button>
                                        </div>
                                    @endif
                                @elseif($card['status'] === 'refused')
                                    <p class="mt-2 text-red-700">{{ __('messages.chat_ui.reasons.'.$card['reason']) }}</p>
                                @elseif($card['status'] === 'applied')
                                    <p class="mt-2 text-gray-600">{{ __('messages.chat_ui.reverse_note') }}</p>
                                @endif
                            </article>
                        @endforeach
                        @foreach($reports[$turn->id] ?? [] as $report)
                            @if($report['url'])
                                <a href="{{ $report['url'] }}" class="ui-link inline-block text-sm">{{ __('messages.chat_ui.download_report') }} <bdi dir="ltr">#{{ $report['id'] }}</bdi></a>
                            @else
                                <p class="text-xs text-gray-500">{{ __('messages.chat_ui.report_status') }} <bdi dir="ltr">#{{ $report['id'] }}</bdi>: {{ __('messages.chat_ui.report_states.'.$report['status']) }}</p>
                            @endif
                        @endforeach
                        @if(in_array($turn->status, ['queued', 'running'], true))
                            <p role="status" class="text-xs text-gray-500">{{ __('messages.chat_ui.working') }}</p>
                        @endif
                    @endforeach
                </div>
                <form wire:submit="send" class="sticky bottom-16 min-w-0 border-t border-line bg-white p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] xl:bottom-0 sm:p-4">
                    <label for="assistant-draft" class="mb-2 block text-sm font-semibold">{{ __('messages.chat_ui.message') }}</label>
                    <textarea id="assistant-draft" wire:model="draft" maxlength="4000" rows="3" @disabled(!$available) class="ui-control block w-full min-w-0 rounded-xl border border-gray-200 p-3 text-sm" placeholder="{{ __('messages.chat_ui.prompt') }}"></textarea>
                    @error('draft')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <span class="text-xs text-gray-500">{{ __('messages.chat_ui.limit') }}</span>
                        <button type="submit" @disabled(!$available) class="rounded-xl bg-signal px-4 py-2 font-semibold text-night disabled:cursor-not-allowed disabled:opacity-50">{{ __('messages.chat_ui.send') }}</button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
