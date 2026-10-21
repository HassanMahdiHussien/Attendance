<div class="space-y-5">
    <x-ui.page-header :title="__('Submit report')" />
    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_19rem]">
    <div class="min-w-0 space-y-5">
    @if($reviewing)
        @php
            // The report body reads in the report's own language and direction, labels included.
            $rl = $assistantSession?->language ?? app()->getLocale();
            $r = fn (string $key): string => trans('report_assistant.review.'.$key, [], $rl);
            $aiSummary = $assistantSession && $assistantSummary !== '' && ($assistantSession->facts['description'] ?? null) === $description;
            $summaryText = $aiSummary ? $assistantSummary : trans('report_assistant.summary_prefix', [], $rl).mb_substr($description, 0, 600);
            $unknownWords = [__('I do not know'), trans('report_assistant.chip.unknown', [], $rl)];
            $placeText = collect([$locations->firstWhere('id', (int) $locationId)?->name, in_array($place, $unknownWords, true) ? null : $place])->filter()->unique()->implode(' — ');
            $dateText = in_array($occurredAtText, $unknownWords, true) ? '' : $occurredAtText;
            // A chat report's type is only its description repeated (HR classifies it), so it shows only when it adds something.
            $freeType = $violationType !== '' && $violationType !== mb_substr($description, 0, 255) ? $violationType : null;
            $listedType = collect([$violationTypes->firstWhere('id', (int) $violationTypeId)?->name(), $freeType])->filter()->implode(' · ');
            $files = $sessionFiles->pluck('original_name')->concat(collect($attachments)->map(fn ($file) => $file->getClientOriginalName()));
            $row = 'grid gap-x-4 gap-y-0.5 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]';
        @endphp
        <section class="ui-card overflow-hidden" aria-labelledby="review-title">
            <header class="border-b border-line px-5 py-4">
                <h2 id="review-title" class="text-lg font-semibold">{{ __('Review report before sending') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('This is what HR will receive. It cannot be changed after sending.') }}</p>
            </header>
            <div dir="{{ $rl === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $rl }}" class="grid gap-6 p-5">
                <div class="grid gap-2 rounded-xl bg-primary-light p-4 sm:px-5">
                    <span class="justify-self-start rounded bg-white px-2 py-0.5 text-xs font-medium text-primary">{{ $aiSummary ? $r('summary_ai') : $r('summary') }}</span>
                    <p class="max-w-[64ch] whitespace-pre-line text-[1.0625rem] leading-loose">{{ $summaryText }}</p>
                </div>
                <section>
                    <h3 class="font-semibold">{{ $r('people') }}</h3>
                    <dl class="divide-y divide-line">
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('employees') }}</dt><dd class="flex flex-wrap gap-1.5">@forelse($people['employees'] as $person)<span dir="auto" class="rounded border border-line bg-bg px-2.5 leading-relaxed">{{ $person['name'] }}</span>@empty<span class="text-gray-600">{{ $r('employees_unknown') }}</span>@endforelse</dd></div>
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('witnesses') }}</dt><dd class="flex flex-wrap gap-1.5">@forelse($people['witnesses'] as $person)<span dir="auto" class="rounded border border-line bg-bg px-2.5 leading-relaxed">{{ $person['name'] }}</span>@empty<span>{{ $witnessesNone ? $r('witnesses_none') : $r('witnesses_unknown') }}</span>@endforelse</dd></div>
                        @if($people['responsible'] !== [])<div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('responsible') }}</dt><dd dir="auto">{{ $people['responsible'][0]['name'] }}</dd></div>@endif
                    </dl>
                </section>
                <section>
                    <h3 class="font-semibold">{{ $r('place_time') }}</h3>
                    <dl class="divide-y divide-line">
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('place') }}</dt><dd><span dir="auto">{{ $placeText ?: $r('unknown') }}</span> <span class="text-gray-600">· {{ $branches->firstWhere('id', (int) $branchId)?->name }}</span></dd></div>
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('date') }}</dt><dd>@if($occurredAt !== '')<bdi class="font-mono text-sm tabular-nums">{{ \App\Support\Format\Ui::date($occurredAt) }} · {{ \App\Support\Format\Ui::date($occurredAt, 'H:i') }}</bdi>@elseif($dateText !== '')<bdi dir="auto">{{ $dateText }}</bdi> <span class="text-gray-600">· {{ $r('time_unknown') }}</span>@else<span class="text-gray-600">{{ $r('unknown') }}</span>@endif</dd></div>
                    </dl>
                </section>
                <section>
                    <h3 class="font-semibold">{{ $r('what') }}</h3>
                    <dl class="divide-y divide-line">
                        @if($listedType)<div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('type') }}</dt><dd>{{ $listedType }}</dd></div>@endif
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('description') }}</dt><dd dir="auto" class="whitespace-pre-line leading-relaxed">{{ $description }}</dd></div>
                        @if($howStarted !== '')<div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('how') }}</dt><dd dir="auto" class="leading-relaxed">{{ $howStarted }}</dd></div>@endif
                        @if($injuriesDamage !== '')<div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('injuries') }}</dt><dd dir="auto" class="leading-relaxed">{{ $injuriesDamage }}</dd></div>@endif
                        {{-- Evidence can be added here, so the chat path never needs the plain form for photos. --}}
                        <div class="{{ $row }}"><dt class="text-sm text-gray-600">{{ $r('attachments') }}</dt><dd x-data x-on:dragover.prevent x-on:drop.prevent="$refs.upload.files = $event.dataTransfer.files; $refs.upload.dispatchEvent(new Event('change', { bubbles: true }))" class="grid justify-items-start gap-1.5 rounded-lg border border-dashed border-line p-3">
                            @if($files->isNotEmpty())<span dir="auto">{{ $files->implode(' · ') }}</span>@endif
                            <input x-ref="upload" id="review-attachments" type="file" wire:model="attachments" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" class="peer sr-only">
                            <label for="review-attachments" class="inline-flex cursor-pointer items-center gap-1.5 rounded text-sm font-medium text-primary hover:underline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary"><x-ui.icon name="plus" class="h-4! w-4!" />{{ $files->isEmpty() ? $r('add_files') : $r('change_files') }}</label>
                            <span wire:loading wire:target="attachments" class="text-sm text-gray-600">{{ $r('uploading') }}</span>
                            @error('attachments')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                            @error('attachments.*')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                            <span class="text-xs text-gray-500">{{ __('report_assistant.review.files_hint') }}</span>
                            <span class="text-xs text-gray-500">{{ __('Drop files here or choose files') }}</span>
                        </dd></div>
                    </dl>
                </section>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4">
                <p class="max-w-[42ch] text-sm text-gray-600">{{ __('If something is wrong, edit the report before sending.') }}</p>
                <div class="flex flex-wrap gap-2.5"><x-ui.button type="button" variant="secondary" wire:click="editReview">{{ __('Edit report') }}</x-ui.button><x-ui.button type="button" wire:click="confirmSend">{{ __('Confirm and send') }}</x-ui.button></div>
            </footer>
        </section>
    @elseif($chatMode)
        @if(!$assistantSession)
            <section class="ui-card overflow-hidden" aria-labelledby="chat-start-title">
                <form wire:submit="startChat" class="grid gap-4 p-5 sm:p-6">
                    @if($branches->isEmpty())<p role="alert" class="rounded-lg bg-signal-light p-3 text-sm">{{ __('Your account is not assigned to any branch yet. Ask HR to add your branch before submitting a report.') }}</p>@endif
                    <div class="grid gap-1">
                        <h2 id="chat-start-title" class="text-lg font-semibold"><label for="chat-first">{{ __('What happened?') }}</label></h2>
                        <p class="max-w-[60ch] text-gray-600">{{ __('Write what happened, or attach an email, document or photos.') }}</p>
                    </div>
                    <div class="rounded-xl border border-line transition-shadow focus-within:border-primary focus-within:ring-4 focus-within:ring-primary-light">
                        <textarea id="chat-first" wire:model="chatInput" rows="5" dir="auto" placeholder="{{ __('Example: two workers had a fight on production line 1 this morning…') }}" class="block w-full resize-y rounded-xl border-0 bg-transparent px-4 py-3 text-base leading-loose placeholder:text-gray-400 focus:outline-none"></textarea>
                    </div>
                    <div x-data x-on:dragover.prevent x-on:drop.prevent="$refs.upload.files = $event.dataTransfer.files; $refs.upload.dispatchEvent(new Event('change', { bubbles: true }))" class="flex flex-wrap items-center justify-between gap-2 border border-dashed border-line px-3 py-2">
                        <input x-ref="upload" id="chat-start-files" type="file" wire:model="attachments" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" class="peer sr-only">
                        <label for="chat-start-files" class="ui-link cursor-pointer text-sm peer-focus-visible:outline-2">{{ __('Attach files') }}</label>
                        <span class="text-xs text-gray-500">{{ __('report_assistant.review.files_hint') }}</span>
                        <span class="text-xs text-gray-500">{{ __('Drop files here or choose files') }}</span>
                    </div>
                    @foreach($attachments as $index => $file)
                        <div class="flex min-w-0 items-center gap-3 rounded-lg border border-line px-3 py-2 text-sm"><span dir="auto" class="min-w-0 flex-1 truncate">{{ $file->getClientOriginalName() }}</span><bdi dir="ltr" class="text-gray-500">{{ number_format($file->getSize() / 1024) }} KB</bdi><button type="button" wire:click="removeUpload({{ $index }})" class="ui-link" aria-label="{{ __('Remove file') }}">×</button></div>
                    @endforeach
                    @error('attachments')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                    @error('attachments.*')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                    @error('chatInput')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                    <div class="text-sm text-gray-600">{{ __('It helps to mention, if you know:') }}<ul class="mt-1 list-disc space-y-0.5 ps-5"><li>{{ __('who was involved, where and when it happened') }}</li><li>{{ __('whether anyone saw it happen') }}</li></ul></div>
                    <div class="flex flex-wrap justify-between gap-3 {{ $branches->count() > 1 ? 'items-end' : 'items-center' }}">
                        @if($branches->count() > 1)
                            <x-ui.field :label="__('Branch')" error="branchId" for="chat-branch" class="min-w-56"><select id="chat-branch" wire:model.live="branchId" class="ui-control"><option value="">{{ __('Select branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-ui.field>
                        @else
                            <p class="text-sm text-gray-600">{{ __('Branch') }}: <span class="font-medium text-ink">{{ $branches->first()?->name }}</span></p>
                        @endif
                        <x-ui.button type="submit">{{ __('Start report') }}</x-ui.button>
                    </div>
                </form>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-3 text-sm text-gray-600 sm:px-6"><span>{{ __('Prefer a form?') }}</span><button type="button" wire:click="usePlainForm" class="ui-link">{{ __('Fill in the form instead') }}</button></div>
            </section>
        @else
            @php
                $lang = $assistantSession->language;
                $missing = app(\App\Services\Reports\ReportAssistant::class)->missing($assistantSession->facts);
                $current = in_array($assistantSession->pending_aspect, $missing, true) ? $assistantSession->pending_aspect : null;
                $chip = 'rounded-full border border-line bg-bg px-3.5 py-1 text-sm text-ink transition-colors duration-150 hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
                $finished = $assistantSession->status === 'ready' && ! $assistantSession->pending_aspect && ! ($assistantSession->facts['pending_match'] ?? null);
            @endphp
            <section class="ui-card overflow-hidden" aria-label="{{ __('Report assistant') }}">
                {{-- The five required answers as the route rail: answered stations fill, the one being asked is the amber point. --}}
                <ol dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" class="grid grid-cols-5 border-b border-line px-2 pb-3 pt-4 text-center text-xs sm:text-sm" aria-label="{{ __('Report progress') }}">
                    @foreach(\App\Services\Reports\ReportAssistant::REQUIRED as $index => $aspect)
                        @php $done = ! in_array($aspect, $missing, true); @endphp
                        <li class="relative min-w-0" aria-current="{{ $aspect === $current ? 'step' : 'false' }}">
                            @if($index > 0)<span class="absolute top-[0.6875rem] h-[3px] w-full -translate-y-1/2 end-1/2 {{ $done && ! in_array(\App\Services\Reports\ReportAssistant::REQUIRED[$index - 1], $missing, true) ? 'bg-night' : 'bg-line' }}" aria-hidden="true"></span>@endif
                            <span class="relative mx-auto mb-1.5 grid h-[1.375rem] w-[1.375rem] place-items-center rounded-full border-[3px] {{ $done ? 'border-night bg-night text-white' : ($aspect === $current ? 'border-signal bg-signal ring-4 ring-signal-light' : 'border-line bg-white') }}" aria-hidden="true">@if($done)<x-ui.icon name="check" class="h-3.5! w-3.5!" stroke-width="3.2" />@endif</span>
                            <span class="block leading-tight {{ $aspect === $current ? 'font-semibold text-ink' : ($done ? 'text-ink' : 'text-gray-500') }}">{{ trans('report_assistant.progress.'.$aspect, [], $lang) }}<span class="sr-only"> · {{ $done ? __('Done') : __('Missing') }}</span></span>
                        </li>
                    @endforeach
                </ol>
                <div dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $lang }}" class="grid max-h-[30rem] content-start gap-3.5 overflow-y-auto p-4 sm:p-5" aria-live="polite" x-data x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })">
                    @foreach($assistantSession->transcript as $line)
                        @if($line['role'] === 'reporter')
                            <div class="ms-auto max-w-[88%] rounded-2xl rounded-ee-md bg-primary-light px-3.5 py-2.5"><span class="sr-only">{{ __('You') }}:</span><p dir="auto" class="whitespace-pre-line leading-relaxed">{{ $line['text'] }}</p></div>
                        @else
                            <div class="flex max-w-[88%] items-end gap-2.5"><span class="grid h-7 w-7 flex-none place-items-center rounded-full bg-night text-white" aria-hidden="true"><x-ui.icon name="chat" class="h-4! w-4!" /></span><div class="rounded-2xl rounded-es-md border border-line bg-white px-3.5 py-2.5"><span class="sr-only">{{ __('Assistant') }}:</span><p dir="auto" class="whitespace-pre-line leading-relaxed">{{ $line['text'] }}</p></div></div>
                        @endif
                    @endforeach
                    @if($assistantSession->status === 'pending')
                        <div wire:poll.2s="pollAssistant" role="status" class="flex items-end gap-2.5"><span class="grid h-7 w-7 flex-none place-items-center rounded-full bg-night text-white" aria-hidden="true"><x-ui.icon name="chat" class="h-4! w-4!" /></span><div class="rounded-2xl rounded-es-md border border-line bg-white px-4 py-3"><span class="report-typing" aria-hidden="true"><i></i><i></i><i></i></span><span class="sr-only">{{ __('Thinking…') }}</span></div></div>
                    @endif
                </div>
                @if($assistantSession->status === 'fallback')<div class="px-4 pb-4 sm:px-5"><x-ui.alert tone="amber">{{ __('The assistant could not finish. Your answers are kept below. Complete what is missing and send your report.') }}</x-ui.alert></div>@endif
                @if($assistantSession->facts['pending_match'] ?? null)
                    <div class="flex flex-wrap gap-2 border-t border-line px-4 py-3.5 sm:px-5"><x-ui.button type="button" wire:click="confirmPersonMatch('yes')">{{ trans('report_assistant.yes', locale: $lang) }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="confirmPersonMatch('other')">{{ trans('report_assistant.someone_else', locale: $lang) }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="confirmPersonMatch('free')">{{ trans('report_assistant.not_in_list', locale: $lang) }}</x-ui.button></div>
                @elseif($assistantSession->status === 'ready' && $assistantSession->pending_aspect)
                    <form wire:submit="answerChat" class="grid gap-2.5 border-t border-line px-4 py-3.5 sm:px-5">
                        <div dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" class="flex flex-wrap gap-1.5">
                            @foreach(($assistantSession->facts['quick_replies'] ?? []) as $index => $reply)<button type="button" class="{{ $chip }}" wire:click="suggestedReply({{ $index }})">{{ $reply }}</button>@endforeach
                            @if($assistantSession->pending_aspect === 'witnesses')<button type="button" class="{{ $chip }}" wire:click="quickReply('none')">{{ trans('report_assistant.chip.none', locale: $lang) }}</button>@endif
                            @if($assistantSession->pending_aspect === 'when')<button type="button" class="{{ $chip }}" wire:click="quickReply('today')">{{ trans('report_assistant.chip.today', locale: $lang) }}</button><button type="button" class="{{ $chip }}" wire:click="quickReply('yesterday')">{{ trans('report_assistant.chip.yesterday', locale: $lang) }}</button>@endif
                            <button type="button" class="{{ $chip }}" wire:click="quickReply('unknown')">{{ trans('report_assistant.chip.unknown', locale: $lang) }}</button>
                        </div>
                        <div x-data x-on:dragover.prevent x-on:drop.prevent="$refs.upload.files = $event.dataTransfer.files; $refs.upload.dispatchEvent(new Event('change', { bubbles: true }))" class="flex items-end gap-2 rounded-xl border border-dashed border-line p-1.5 transition-shadow focus-within:border-primary focus-within:ring-4 focus-within:ring-primary-light">
                            <input x-ref="upload" id="chat-more-files" type="file" wire:model="attachments" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" class="peer sr-only">
                            <label for="chat-more-files" class="grid h-10 w-10 flex-none cursor-pointer place-items-center rounded-lg bg-bg text-primary peer-focus-visible:outline-2" aria-label="{{ __('Attach files') }}"><x-ui.icon name="plus" class="h-4! w-4!" /></label>
                            <label for="chat-answer" class="sr-only">{{ __('Your answer') }}</label>
                            <textarea id="chat-answer" wire:model="chatInput" rows="1" dir="auto" placeholder="{{ __('Type your answer…') }}" class="block max-h-40 min-h-10 min-w-0 flex-1 resize-none border-0 bg-transparent px-2 py-2 leading-relaxed placeholder:text-gray-400 focus:outline-none" x-data x-init="$el.focus()" x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'" x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"></textarea>
                            <button type="submit" class="ui-button ui-button-primary h-10 w-10 flex-none p-0" aria-label="{{ __('Send') }}"><x-ui.icon name="arrow" class="h-[1.125rem]! w-[1.125rem]! ltr:rotate-180" stroke-width="2.2" /></button>
                        </div>
                        @foreach($sessionFiles as $file)
                            <div class="flex min-w-0 items-center gap-3 rounded-lg border border-line px-3 py-2 text-sm"><span dir="auto" class="min-w-0 flex-1 truncate">{{ $file->original_name }}</span><bdi dir="ltr" class="text-gray-500">{{ number_format($file->size_bytes / 1024) }} KB</bdi><button type="button" wire:click="removeSessionFile({{ $file->id }})" class="ui-link" aria-label="{{ __('Remove file') }}">×</button></div>
                        @endforeach
                        @error('attachments')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                        @error('chatInput')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                        <p class="text-xs text-gray-500">{{ __('Drop files here or choose files') }} · {{ __('report_assistant.review.files_hint') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Question :count of 6 at most', ['count' => max(1, $assistantSession->question_count)]) }}</p>
                    </form>
                @elseif($finished)
                    <div class="mx-4 mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-green-light px-4 py-3.5 sm:mx-5"><span class="inline-flex items-center gap-2 font-semibold text-green"><x-ui.icon name="check" stroke-width="2.4" />{{ __('Everything needed is here') }}</span><x-ui.button type="button" wire:click="continueToReview">{{ __('Review report') }}</x-ui.button></div>
                @endif
                @if(!($assistantSession->status === 'ready' && $assistantSession->pending_aspect))
                    <div x-data x-on:dragover.prevent x-on:drop.prevent="$refs.upload.files = $event.dataTransfer.files; $refs.upload.dispatchEvent(new Event('change', { bubbles: true }))" class="grid gap-2 border border-dashed border-line px-4 py-3 sm:px-5">
                        <input x-ref="upload" id="chat-any-files" type="file" wire:model="attachments" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" class="peer sr-only">
                        <label for="chat-any-files" class="ui-link inline-flex cursor-pointer items-center gap-2 text-sm peer-focus-visible:outline-2"><x-ui.icon name="plus" class="h-4! w-4!" />{{ __('Attach files') }}</label>
                        <p class="text-xs text-gray-500">{{ __('Drop files here or choose files') }} · {{ __('report_assistant.review.files_hint') }}</p>
                        @foreach($sessionFiles as $file)
                            <div class="flex min-w-0 items-center gap-3 rounded-lg border border-line px-3 py-2 text-sm"><span dir="auto" class="min-w-0 flex-1 truncate">{{ $file->original_name }}</span><bdi dir="ltr" class="text-gray-500">{{ number_format($file->size_bytes / 1024) }} KB</bdi><button type="button" wire:click="removeSessionFile({{ $file->id }})" class="ui-link" aria-label="{{ __('Remove file') }}">×</button></div>
                        @endforeach
                        @error('attachments')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3 text-sm text-gray-600 sm:px-5"><span>{{ __('Prefer a form?') }}</span><button type="button" wire:click="usePlainForm" class="ui-link">{{ __('Fill in the form instead') }}</button></div>
            </section>
        @endif
    @else
    @if($assistantSession && $assistantSession->status === 'fallback')<x-ui.alert tone="amber">{{ __('The assistant could not finish. Your answers are kept below. Complete what is missing and send your report.') }}</x-ui.alert>@endif
    @if($assistantSession && $assistantSession->status === 'fallback')<div class="space-y-2 rounded-lg border border-line bg-bg p-3">@foreach($assistantSession->transcript as $line)<p dir="auto" class="text-sm"><span class="font-semibold">{{ $line['role'] === 'reporter' ? __('You') : __('Assistant') }}:</span> {{ $line['text'] }}</p>@endforeach</div>@endif
    <form wire:submit="submit" class="space-y-5">
    @if($branches->isEmpty())
        <p role="alert" class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('Your account is not assigned to any branch yet. Ask HR to add your branch before submitting a report.') }}</p>
    @endif
    <x-ui.card class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Branch')" error="branchId" for="report-branch">
                <select id="report-branch" wire:model.live="branchId" class="ui-control"><option value="">{{ __('Select branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                @error('branchId')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.people-picker kind="employees" :search="$peopleSearch['employees'] ?? ''" :label="__('Employee concerned')" :items="$people['employees']" :results="$peopleResults['employees']" />
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="employeesUnknown">{{ __('I do not know who was involved') }}</label>@error('people.employees')<p role="alert" class="text-sm text-overdue-red">{{ $message }}</p>@enderror
            <x-ui.field :label="__('Location')" for="report-location">
                <select id="report-location" wire:model="locationId" class="ui-control"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Place if not listed')" error="place" for="report-place">
                <x-ui.input id="report-place" wire:model="place" /><button type="button" wire:click="unknownPlace" class="ui-link mt-1 text-sm">{{ __('I do not know') }}</button>@error('place')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Incident date and time')" error="occurredAt" for="report-occurred-at">
                <x-ui.input id="report-occurred-at" type="datetime-local" wire:model="occurredAt" />@error('occurredAt')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Approximate date or I do not know')" error="occurredAtText" for="report-occurred-at-text"><x-ui.input id="report-occurred-at-text" wire:model="occurredAtText" /><button type="button" wire:click="unknownDate" class="ui-link mt-1 text-sm">{{ __('I do not know') }}</button></x-ui.field>
            <x-ui.field :label="__('Violation type')" error="violationType" for="report-violation-type-id">
                <x-ui.select id="report-violation-type-id" wire:model="violationTypeId"><option value="">{{ __('Other — describe below') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select>
                <x-ui.input id="report-violation-type" wire:model="violationType" :placeholder="__('Violation if not listed')" class="mt-2" />@error('violationType')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
        </div>
        <x-ui.people-picker kind="witnesses" :search="$peopleSearch['witnesses'] ?? ''" :label="__('Witnesses')" :items="$people['witnesses']" :results="$peopleResults['witnesses']" />
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="witnessesNone">{{ __('No witnesses') }}</label>@error('witnessesNone')<p role="alert" class="text-sm text-overdue-red">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="witnessesUnknown">{{ __('I do not know if there were witnesses') }}</label>
        <x-ui.field :label="__('Incident description')" error="description" for="report-description">
            <textarea id="report-description" wire:model="description" rows="5" class="ui-control"></textarea>@error('description')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.field :label="__('How it started (optional)')" for="report-how-started"><textarea id="report-how-started" wire:model="howStarted" rows="2" dir="auto" class="ui-control"></textarea></x-ui.field>
        <x-ui.field :label="__('Injuries or damage (optional)')" for="report-injuries"><textarea id="report-injuries" wire:model="injuriesDamage" rows="2" dir="auto" class="ui-control"></textarea></x-ui.field>
        <x-ui.field :label="__('Attachments (email, documents, images or video)')" error="attachments.*" for="report-attachments">
            <div x-data x-on:dragover.prevent x-on:drop.prevent="$refs.upload.files = $event.dataTransfer.files; $refs.upload.dispatchEvent(new Event('change', { bubbles: true }))" class="rounded-lg border border-dashed border-line p-3">
                <input x-ref="upload" id="report-attachments" type="file" wire:model="attachments" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp,.mp4,.mov" class="ui-control">
                <p class="mt-2 text-xs text-gray-500">{{ __('Drop files here or choose files') }} · {{ trans('report_assistant.review.files_hint') }}</p>
            </div>@error('attachments.*')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.button type="submit">{{ __('Send report') }}</x-ui.button>
    </x-ui.card>
    </form>
    @endif
    </div>
    @if($chatMode || $reviewing)
        @php
            // Where the reporter is in the three steps; navy, not amber, so the screen keeps its two amber points.
            $chatDone = $assistantSession && $assistantSession->status === 'ready' && ! $assistantSession->pending_aspect && ! ($assistantSession->facts['pending_match'] ?? null);
            $guideStep = $reviewing || $chatDone ? 3 : ($assistantSession ? 2 : 1);
            $guide = [
                1 => [__('Describe what happened'), __('In your own words. There is no form to fill in.')],
                2 => [__('Answer a few questions'), __('Only what is missing for HR, usually one or two.')],
                3 => [__('Check and send'), __('You read the summary before it goes to HR.')],
            ];
        @endphp
        <aside class="hidden xl:block" aria-label="{{ __('How reporting works') }}">
            <div class="ui-card p-5">
                <h2 class="font-semibold">{{ __('How reporting works') }}</h2>
                <ol class="mt-4">
                    @foreach($guide as $number => $step)
                        <li class="relative flex gap-3 pb-5 last:pb-0" aria-current="{{ $number === $guideStep ? 'step' : 'false' }}">
                            @if($number < 3)<span class="absolute start-[0.6875rem] top-6 h-[calc(100%-1.5rem)] w-0.5 {{ $number < $guideStep ? 'bg-night' : 'bg-line' }}" aria-hidden="true"></span>@endif
                            <span class="relative grid h-[1.375rem] w-[1.375rem] flex-none place-items-center rounded-full border-2 text-[0.7rem] font-semibold {{ $number < $guideStep ? 'border-night bg-night text-white' : ($number === $guideStep ? 'border-primary bg-primary text-white ring-4 ring-primary-light' : 'border-line bg-white text-gray-500') }}" aria-hidden="true">@if($number < $guideStep)<x-ui.icon name="check" class="h-3! w-3!" stroke-width="3" />@else{{ $number }}@endif</span>
                            <span class="min-w-0"><span class="block text-sm {{ $number === $guideStep ? 'font-semibold text-ink' : 'font-medium text-gray-700' }}">{{ $step[0] }}</span><span class="mt-0.5 block text-sm leading-relaxed text-gray-600">{{ $step[1] }}</span></span>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-5 border-t border-line pt-4 text-sm leading-relaxed text-gray-600">{{ __('Your report goes to HR. You can follow its status in My reports.') }}</p>
            </div>
        </aside>
    @endif
    </div>
</div>
