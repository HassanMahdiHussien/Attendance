<div class="space-y-4 rounded-xl border border-line bg-white p-4 sm:p-5" @if($this->source()->ai_status === 'pending' && $this->source()->updated_at->gt(now()->subMinutes(10))) wire:poll.3s="refreshProposal" @elseif(($progress = $this->progress()) && !$progress['finished']) wire:poll.3s @endif>
    @php($source = $this->source())
    <p class="font-semibold" dir="auto">{{ $source->file_name }}</p>
    @if($holiday = $this->holiday())
        <p class="text-green-800">{{ $holiday->status === 'draft' ? __('messages.announcement.draft_exists') : __('messages.announcement.already_approved') }} <a class="ui-link" href="{{ $this->detailsUrl($holiday->holiday_id) }}">{{ __('messages.announcement.see_details') }}</a></p>
        @if($progress = $this->progress())
            <p>{{ $progress['failed'] ? __('messages.announcement.recalculation_failed') : ($progress['finished'] ? __('messages.announcement.done', ['count' => $progress['people']]) : __('messages.announcement.recalculating', ['done' => $progress['done'], 'total' => $progress['total']])) }}</p>
        @elseif($holiday->holiday->status !== 'draft')
            <p>{{ __('messages.announcement.done', ['count' => 0]) }}</p>
        @endif
    @elseif($source->closed_at)
        <p>{{ __('messages.announcement.no_change') }}</p><button type="button" wire:click="reopen" class="ui-link">{{ __('messages.announcement.reopen') }}</button>
    @elseif($source->ai_status !== 'done')
        <p>{{ $source->ai_status === 'pending' && $source->updated_at->gt(now()->subMinutes(10)) ? __('messages.announcement.reading') : __('messages.announcement.read_failed') }}</p>
        @if($source->error_code)<p class="text-red-700">{{ __('messages.holidays.ai_errors.'.$source->error_code) }}</p>@endif
        @if($source->ai_status !== 'pending' || $source->updated_at->lte(now()->subMinutes(10)))<button type="button" wire:click="retry" class="ui-link">{{ __('messages.announcement.retry') }}</button>@endif
        <a class="ui-link" href="{{ $this->detailsUrl() }}">{{ __('messages.holiday_ui.enter_by_hand') }}</a>
    @else
        @php($proposal = $source->proposal ?? [])
        <section class="space-y-2">
            <h3 class="font-semibold">{{ __('messages.announcement.what') }}</h3>
            <select wire:model.live="kind" class="rounded-lg border border-line p-2"><option value="">{{ __('messages.announcement.choose_kind') }}</option><option value="holiday">{{ __('messages.announcement.kinds.holiday') }}</option><option value="field_work">{{ __('messages.announcement.kinds.field_work') }}</option><option value="notice">{{ __('messages.announcement.kinds.notice') }}</option></select>
            @if($kind === null)<p class="text-amber-800">{{ __('messages.announcement.question_kind') }}</p>@endif
            <label class="block text-sm">{{ __('messages.holiday_ui.name') }}<input wire:model.live="name" class="mt-1 block w-full rounded-lg border border-line p-2"></label>
            <p class="text-sm">{{ $proposal['summary'] ?? '' }}</p>
            @foreach(array_merge($proposal['scope_quotes'] ?? [], $proposal['exception_quotes'] ?? []) as $quote)<blockquote class="border-s-2 border-line ps-3 text-sm" dir="auto">{{ $quote }}</blockquote>@endforeach
        </section>
        <section class="space-y-2 border-t border-line pt-3">
            <h3 class="font-semibold">{{ __('messages.announcement.dates') }}</h3>
            <div class="flex flex-wrap gap-3"><label>{{ __('messages.holiday_ui.from') }} <input type="date" wire:model.live="from" class="rounded-lg border border-line p-2"></label><label>{{ __('messages.holiday_ui.to') }} <input type="date" wire:model.live="to" class="rounded-lg border border-line p-2"></label></div>
            @foreach($extraRanges as $index => $range)<div class="flex flex-wrap gap-3" wire:key="announcement-range-{{ $index }}"><label>{{ __('messages.holiday_ui.from') }} <input type="date" wire:model.live="extraRanges.{{ $index }}.from" class="rounded-lg border border-line p-2"></label><label>{{ __('messages.holiday_ui.to') }} <input type="date" wire:model.live="extraRanges.{{ $index }}.to" class="rounded-lg border border-line p-2"></label><button type="button" wire:click="removeRange({{ $index }})" class="ui-link">{{ __('messages.holiday_ui.remove_range') }}</button></div>@endforeach
            <button type="button" wire:click="addRange" class="ui-link">{{ __('messages.holiday_ui.add_range') }}</button>
            @if(!$from || !$to)<p class="text-amber-800">{{ __('messages.announcement.question_dates') }}</p>@endif
        </section>
        <section class="space-y-3 border-t border-line pt-3">
            <h3 class="font-semibold">{{ __('messages.announcement.who') }}</h3>
            <label class="block"><input type="radio" wire:model.live="who" value="everyone"> {{ __('messages.announcement.everyone') }}</label>
            <label class="block"><input type="radio" wire:model.live="who" value="selected"> {{ __('messages.announcement.selected') }}</label>
            @if(!$who)<p class="text-amber-800">{{ __('messages.announcement.question_who') }}</p>@endif
            @if($who === 'selected')
                <label class="block">{{ __('messages.announcement.departments') }}<input type="search" wire:model.live.debounce.250ms="departmentSearch" class="mt-1 block w-full rounded-lg border border-line p-2" aria-label="{{ __('messages.announcement.departments') }}"></label>
                <div class="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-line p-2">@foreach($this->departmentOptions() as $department)<label class="flex items-center gap-2"><input type="checkbox" value="{{ $department }}" wire:model.live="departments"> <span dir="auto">{{ $department }}</span></label>@endforeach</div>
                <div class="flex flex-wrap gap-2">@foreach($this->departments as $department)<button type="button" wire:click="removeDepartment({{ Js::from($department) }})" class="rounded-full border border-line px-3 py-1 text-sm" aria-label="{{ __('messages.announcement.remove_department', ['name' => $department]) }}">{{ $department }} ×</button>@endforeach</div>
                @foreach($proposal['suggested_departments'] ?? [] as $suggestion)
                    @if(!in_array($suggestion, $this->departments, true))<button type="button" wire:click="addDepartment({{ Js::from($suggestion) }})" class="ui-link text-sm">{{ __('messages.announcement.suggested', ['name' => $suggestion]) }}</button>@endif
                @endforeach
                <label class="block">{{ __('messages.announcement.employee_list') }}<input type="file" wire:model="peopleFile" accept=".xlsx,.csv" class="mt-1 block w-full rounded-lg border border-line p-2"></label>
                @error('peopleFile')<p class="text-red-700">{{ $message }}</p>@enderror
                @if(!$this->peopleChosen())<p class="text-amber-800">{{ __($kind === 'field_work' ? 'messages.announcement.question_field_people' : 'messages.announcement.question_who') }}</p>@endif
            @endif
            <div class="grid gap-3 sm:grid-cols-3">
                <label>{{ __('messages.holiday_ui.branches') }}<select wire:model.live="branchIds" multiple class="block w-full rounded-lg border border-line p-2">@foreach(\App\Models\Branch::query()->where('company_id', $source->company_id)->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label>{{ __('messages.holiday_ui.legal_entities') }}<select wire:model.live="legalEntities" multiple class="block w-full rounded-lg border border-line p-2">@foreach(\App\Models\Employee::query()->where('company_id', $source->company_id)->whereNotNull('legal_entity')->distinct()->pluck('legal_entity') as $entity)<option value="{{ $entity }}">{{ $entity }}</option>@endforeach</select></label>
                <label>{{ __('messages.holiday_ui.work_patterns') }}<select wire:model.live="workPatterns" multiple class="block w-full rounded-lg border border-line p-2">@foreach(['office', 'shift', 'security'] as $pattern)<option value="{{ $pattern }}">{{ __('messages.holiday_ui.patterns.'.$pattern) }}</option>@endforeach</select></label>
            </div>
        </section>
        @if($preview = $this->preview())
            <section class="space-y-2 border-t border-line pt-3"><h3 class="font-semibold">{{ __('messages.announcement.preview') }} — {{ __('messages.announcement.preview_date', ['date' => $from]) }}</h3>
                <p>{{ __('messages.announcement.preview_counts', ['count' => $preview['count'], 'with' => $preview['with_punches'], 'without' => $preview['without_punches']]) }}</p>
                @foreach($preview['by_branch'] as $branch => $counts)<p class="text-sm">{{ $branch }}: {{ $counts['total'] }} ({{ $counts['with_punches'] }} {{ __('messages.announcement.with_punches') }}, {{ $counts['total'] - $counts['with_punches'] }} {{ __('messages.announcement.without_punches') }})</p>@endforeach
                <ul class="text-sm">@foreach($preview['sample'] as $person)<li>{{ $person['code'] }} — {{ $person['name'] }}</li>@endforeach</ul>
            </section>
        @endif
        <section class="space-y-2 border-t border-line pt-3"><h3 class="font-semibold">{{ __('messages.announcement.questions') }}</h3>
            @if(($proposal['expected_people'] ?? null) !== null && $preview && $proposal['expected_people'] !== $preview['count'])<p class="text-amber-800">{{ __('messages.announcement.headcount', ['expected' => $proposal['expected_people'], 'selected' => $preview['count']]) }}</p>@endif
            @if($unknownCodes)<p class="text-amber-800">{{ __('messages.announcement.unknown_codes', ['count' => count($unknownCodes), 'codes' => implode(', ', array_slice($unknownCodes, 0, 20))]) }}</p>@endif
            @if(($proposal['confidence'] ?? null) === 'low')<p class="text-amber-800">{{ __('messages.announcement.low_confidence') }}</p>@endif
            @if($proposal['mentions_missing_attachment'] ?? false)<p class="text-amber-800">{{ __('messages.announcement.missing_attachment') }}</p>@endif
            @foreach($source->parts ?? [] as $part)@if(($part['kind'] ?? null) === 'skipped')<p class="text-amber-800">{{ __('messages.announcement.'.(($part['reason'] ?? null) === 'limit' ? 'skipped_limit' : 'skipped'), ['name' => $part['name']]) }}</p>@endif @endforeach
            @if($kind === 'field_work' && $who === 'everyone' && $preview)<label class="block"><input type="checkbox" wire:model.live="everyoneConfirmed"> {{ __('messages.announcement.confirm_everyone', ['count' => $preview['count']]) }}</label>@endif
        </section>
        <section class="space-y-3 border-t border-line pt-3"><label class="block">{{ __('messages.announcement.note') }}<input wire:model="note" class="mt-1 block w-full rounded-lg border border-line p-2"></label>
            @error('kind')<p class="text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-4"><button type="button" wire:click="approve" @disabled(!$from || !$to || !$who || !$kind || $kind === 'notice' || ($who === 'selected' && !$this->peopleChosen()) || ($kind === 'field_work' && $who === 'everyone' && !$everyoneConfirmed)) class="rounded-lg bg-signal px-4 py-2 font-semibold text-night disabled:opacity-50">{{ __('messages.holiday_ui.approve') }}</button><button type="button" wire:click="close" class="ui-link">{{ __('messages.announcement.no_change') }}</button><a class="ui-link" href="{{ $this->detailsUrl() }}">{{ __('messages.announcement.see_details') }}</a></div>
        </section>
    @endif
</div>
