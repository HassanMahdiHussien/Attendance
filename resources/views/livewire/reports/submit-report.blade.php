<div class="max-w-3xl space-y-5">
    <x-ui.page-header :title="__('Submit report')" />
    @if($reviewing)
        <x-ui.card class="space-y-5" aria-label="{{ __('Review report before sending') }}">
            <h2 class="text-lg font-semibold">{{ __('Review report before sending') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><dt class="font-medium">{{ __('Reporter summary') }}</dt><dd dir="auto" class="mt-1 whitespace-pre-line rounded-lg border border-line bg-bg p-3">{{ $assistantSession && $assistantSummary !== '' && ($assistantSession->facts['description'] ?? null) === $description ? $assistantSummary : (($assistantSession ? trans('report_assistant.summary_prefix', locale: $assistantSession->language) : __('According to the reporter: ')).mb_substr($description, 0, 600)) }}</dd></div>
                <div><dt class="font-medium">{{ __('Employees named in report') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ collect($people['employees'])->pluck('name')->implode(' · ') ?: __('Not specified') }}</dd></div>
                @if($employeesUnknown)<div><dt class="font-medium">{{ __('Employee identity') }}</dt><dd>{{ __('I do not know') }}</dd></div>@endif
                <div><dt class="font-medium">{{ __('Witnesses') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ collect($people['witnesses'])->pluck('name')->implode(' · ') ?: __('Not specified') }}</dd></div>
                @if($witnessesNone || $witnessesUnknown)<div><dt class="font-medium">{{ __('Witness answer') }}</dt><dd>{{ $witnessesNone ? __('None') : __('I do not know') }}</dd></div>@endif
                @if($people['responsible'] !== [])<div><dt class="font-medium">{{ __('Alleged responsible person') }}</dt><dd dir="auto">{{ $people['responsible'][0]['name'] }}</dd></div>@endif
                <div><dt class="font-medium">{{ __('Branch') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $branches->firstWhere('id', (int) $branchId)?->name }}</dd></div>
                <div><dt class="font-medium">{{ __('Location') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $locations->firstWhere('id', (int) $locationId)?->name ?? __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Place if not listed') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ $place ?: __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Incident date and time') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>@if($occurredAt !== '')<bdi>{{ \App\Support\Format\Ui::date($occurredAt) }} · {{ \App\Support\Format\Ui::date($occurredAt, 'H:i') }}</bdi>@else{{ __('Not specified') }}@endif</dd></div>
                @if($occurredAtText !== '')<div><dt class="font-medium">{{ __('Date as reported') }}</dt><dd dir="auto">{{ $occurredAtText }}</dd></div>@endif
                <div><dt class="font-medium">{{ __('Violation type') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $violationTypes->firstWhere('id', (int) $violationTypeId)?->name() ?? ($violationType ?: __('Not specified')) }} @if($violationTypeId !== '' && $violationType !== '') · {{ $violationType }} @endif</dd></div>
                <div class="sm:col-span-2"><dt class="font-medium">{{ __('Incident description') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto" class="whitespace-pre-line">{{ $description }}</dd></div>
                @if($howStarted !== '')<div class="sm:col-span-2"><dt class="font-medium">{{ __('How it started (optional)') }}</dt><dd dir="auto">{{ $howStarted }}</dd></div>@endif
                @if($injuriesDamage !== '')<div class="sm:col-span-2"><dt class="font-medium">{{ __('Injuries or damage (optional)') }}</dt><dd dir="auto">{{ $injuriesDamage }}</dd></div>@endif
                <div class="sm:col-span-2"><dt class="font-medium">{{ __('Attachments') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ collect($attachments)->map(fn ($file) => $file->getClientOriginalName())->implode(' · ') ?: __('Not specified') }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-3"><x-ui.button type="button" variant="secondary" wire:click="editReview">{{ __('Edit report') }}</x-ui.button><x-ui.button type="button" wire:click="confirmSend">{{ __('Confirm and send') }}</x-ui.button></div>
        </x-ui.card>
    @elseif($chatMode)
        <x-ui.card class="space-y-5" aria-label="{{ __('Report assistant') }}">
            <h2 class="text-lg font-semibold">{{ __('Report assistant') }}</h2>
            <p class="text-sm text-gray-600">{{ __('Tell us what happened. You can edit every field before sending.') }}</p>
            @if(!$assistantSession)
                @if($branches->isEmpty())<p role="alert" class="rounded-lg bg-signal-light p-3 text-sm">{{ __('Your account is not assigned to any branch yet. Ask HR to add your branch before submitting a report.') }}</p>@endif
                <form wire:submit="startChat" class="space-y-4">
                    <x-ui.field :label="__('Branch')" error="branchId" for="chat-branch"><select id="chat-branch" wire:model.live="branchId" class="ui-control"><option value="">{{ __('Select branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field :label="__('What happened?')" error="chatInput" for="chat-first"><textarea id="chat-first" wire:model="chatInput" rows="4" dir="auto" class="ui-control"></textarea></x-ui.field>
                    <x-ui.button type="submit">{{ __('Start report') }}</x-ui.button>
                </form>
            @else
                @php($missing = app(\App\Services\Reports\ReportAssistant::class)->missing($assistantSession->facts))
                <div class="flex flex-wrap gap-2" aria-label="{{ __('Report progress') }}">@foreach(\App\Services\Reports\ReportAssistant::REQUIRED as $aspect)<span class="rounded border px-2 py-1 text-xs {{ in_array($aspect, $missing, true) ? 'border-line text-gray-600' : 'border-green bg-green-light text-green' }}">{{ __('report_assistant.progress.'.$aspect) }}</span>@endforeach</div>
                <div class="max-h-[28rem] space-y-3 overflow-y-auto rounded-lg bg-bg p-3" aria-live="polite" x-data x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })">@foreach($assistantSession->transcript as $line)<div class="{{ $line['role'] === 'reporter' ? 'ms-auto bg-primary-light' : 'me-auto bg-white' }} max-w-[90%] rounded-lg border border-line p-3 text-sm"><span class="block text-xs font-semibold">{{ $line['role'] === 'reporter' ? __('You') : __('Assistant') }}</span><p dir="auto" class="whitespace-pre-line">{{ $line['text'] }}</p></div>@endforeach</div>
                @if($assistantSession->status === 'pending')<div wire:poll.2s="pollAssistant" role="status" class="text-sm text-primary">{{ __('Thinking…') }}</div>@endif
                @if($assistantSession->status === 'fallback')<x-ui.alert tone="amber">{{ __('The assistant is unavailable. Complete the form below to send your report.') }}</x-ui.alert>@endif
                @if($assistantSession->facts['pending_match'] ?? null)
                    @php($match = $assistantSession->facts['pending_match'])
                    <div class="flex flex-wrap gap-2"><x-ui.button type="button" wire:click="confirmPersonMatch('yes')">{{ trans('report_assistant.yes', locale: $assistantSession->language) }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="confirmPersonMatch('other')">{{ trans('report_assistant.someone_else', locale: $assistantSession->language) }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="confirmPersonMatch('free')">{{ trans('report_assistant.not_in_list', locale: $assistantSession->language) }}</x-ui.button></div>
                @elseif($assistantSession->status === 'ready' && $assistantSession->pending_aspect)
                    <form wire:submit="answerChat" class="space-y-3"><x-ui.field :label="__('Your answer')" error="chatInput" for="chat-answer"><textarea id="chat-answer" wire:model="chatInput" rows="3" dir="auto" class="ui-control"></textarea></x-ui.field><div class="flex flex-wrap gap-2"><x-ui.button type="submit">{{ __('Reply') }}</x-ui.button>@foreach(($assistantSession->facts['quick_replies'] ?? []) as $index => $reply)<x-ui.button type="button" variant="secondary" wire:click="suggestedReply({{ $index }})">{{ $reply }}</x-ui.button>@endforeach<x-ui.button type="button" variant="secondary" wire:click="quickReply('unknown')">{{ trans('report_assistant.chip.unknown', locale: $assistantSession->language) }}</x-ui.button>@if($assistantSession->pending_aspect === 'when')<x-ui.button type="button" variant="secondary" wire:click="quickReply('today')">{{ trans('report_assistant.chip.today', locale: $assistantSession->language) }}</x-ui.button><x-ui.button type="button" variant="secondary" wire:click="quickReply('yesterday')">{{ trans('report_assistant.chip.yesterday', locale: $assistantSession->language) }}</x-ui.button>@endif @if($assistantSession->pending_aspect === 'witnesses')<x-ui.button type="button" variant="secondary" wire:click="quickReply('none')">{{ trans('report_assistant.chip.none', locale: $assistantSession->language) }}</x-ui.button>@endif</div></form>
                @elseif($assistantSession->status === 'ready')
                    <x-ui.button type="button" wire:click="continueToReview">{{ __('Continue to report details') }}</x-ui.button>
                @endif
            @endif
            <button type="button" wire:click="usePlainForm" class="ui-link text-sm">{{ __('Use the form instead') }}</button>
        </x-ui.card>
    @else
    @if($assistantSession && $assistantSession->status === 'fallback')<x-ui.alert tone="amber">{{ __('The assistant is unavailable. Complete the form below to send your report.') }}</x-ui.alert>@endif
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
        <x-ui.field :label="__('Attachments (PDF or images, up to 10 MB per file)')" error="attachments.*" for="report-attachments">
            <input id="report-attachments" type="file" wire:model="attachments" multiple accept=".pdf,.jpg,.jpeg,.png" class="ui-control">@error('attachments.*')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.button type="submit">{{ __('Send report') }}</x-ui.button>
    </x-ui.card>
    </form>
    @endif
</div>
