<div class="mx-auto max-w-5xl space-y-5">
    <x-ui.page-header :title="__('Committee session')" :subtitle="__('Select the attendees and each member’s vote. At least three members must attend, including a labor union representative. Vote again if there is no majority.')" back="/cases/{{ $case->id }}" />
    <x-ui.card class="space-y-5">
        @if($case->confirmedResponseText())<section class="rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">{{ __('Transcribed text — the scan is the original') }}</h2><p class="whitespace-pre-wrap" dir="auto">{{ $case->confirmedResponseText() }}</p></section>@endif
        @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }} @if($case->session_majority_reached && $case->status === \App\Enums\CaseStatus::CommitteeSessionHeld)<a href="/cases/{{ $case->id }}" class="ui-link underline">{{ __('Write recommendations') }}</a>@endif</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        <div class="space-y-2">
            @foreach($members as $member)
                <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-gray-50 p-3" wire:key="member-{{ $member->id }}">
                    <label class="flex min-h-10 flex-1 items-center gap-2"><input type="checkbox" wire:model.live="selected.{{ $member->id }}" aria-label="{{ $member->person_name }}"><span>{{ $member->person_name }}</span><small class="text-gray-500">{{ ['legal' => __('Legal'), 'hr' => __('HR'), 'labor_union' => __('Labor union')][$member->role_type->value] }}</small></label>
                    @if($selected[$member->id] ?? false)<x-ui.select wire:model="votes.{{ $member->id }}" class="sm:w-auto"><option value="">{{ __('Vote') }}</option><option value="approve">{{ __('Approve') }}</option><option value="reject">{{ __('Reject') }}</option><option value="abstain">{{ __('Abstain') }}</option></x-ui.select>@endif
                </div>
            @endforeach
        </div>
        <label class="block font-bold">{{ __('Session chair') }}<x-ui.select wire:model="chairId" class="mt-2"><option value="">{{ __('Select the chair') }}</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</x-ui.select></label>
        <label class="block font-bold">{{ __('Minutes author') }}<x-ui.select wire:model="minutesTypedBy" class="mt-2"><option value="">{{ __('Who wrote the minutes?') }}</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</x-ui.select></label>
        <label class="block font-bold">{{ __('Session minutes') }}<x-ui.textarea wire:model="minutes" rows="8" class="mt-2" /></label>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="font-bold">{{ __('Outcome') }}<x-ui.select wire:model="outcome" class="mt-2"><option value="recommendation_only">{{ __('Recommendation without penalty') }}</option><option value="warning">{{ __('Warning') }}</option><option value="termination">{{ __('Termination') }}</option></x-ui.select></label>
            <label class="font-bold">{{ __('Employee defense considered') }}<x-ui.select wire:model="defenseAcceptance" class="mt-2"><option value="accepted_fully">{{ __('Fully') }}</option><option value="accepted_partially">{{ __('Partially') }}</option><option value="rejected">{{ __('Not accepted') }}</option></x-ui.select></label>
        </div>
        @foreach(['selected', 'chairId', 'minutesTypedBy', 'minutes'] as $field)@error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror@endforeach
        <x-ui.button wire:click="save">{{ __('Save session') }}</x-ui.button>
    </x-ui.card>
</div>
