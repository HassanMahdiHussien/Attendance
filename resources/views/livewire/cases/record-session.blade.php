<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">{{ __('Committee session') }}</h1>
        @if($case->confirmedResponseText())<section class="bg-gray-50 rounded-2xl p-4"><h2 class="font-bold">{{ __('Transcribed text — the scan is the original') }}</h2><p class="whitespace-pre-wrap" dir="auto">{{ $case->confirmedResponseText() }}</p></section>@endif
        <p class="text-gray-500">{{ __('Select the attendees and each member’s vote. At least three members must attend, including a labor union representative. Vote again if there is no majority.') }}</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }} @if($case->session_majority_reached && $case->status === \App\Enums\CaseStatus::CommitteeSessionHeld)<a href="/cases/{{ $case->id }}" class="underline">{{ __('Write recommendations') }}</a>@endif</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        <div class="space-y-2">
            @foreach($members as $member)
                <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-gray-50 p-3" wire:key="member-{{ $member->id }}">
                    <label class="flex-1 flex gap-2 items-center"><input type="checkbox" wire:model.live="selected.{{ $member->id }}" aria-label="{{ $member->person_name }}"><span>{{ $member->person_name }}</span><small class="text-gray-500">{{ ['legal'=>__('Legal'),'hr'=>__('HR'),'labor_union'=>__('Labor union')][$member->role_type->value] }}</small></label>
                    @if($selected[$member->id] ?? false)<select wire:model="votes.{{ $member->id }}" class="rounded-xl border p-2"><option value="">{{ __('Vote') }}</option><option value="approve">{{ __('Approve') }}</option><option value="reject">{{ __('Reject') }}</option><option value="abstain">{{ __('Abstain') }}</option></select>@endif
                </div>
            @endforeach
        </div>
        <label class="block font-bold">{{ __('Session chair') }} <select wire:model="chairId" class="block w-full rounded-xl border p-3 mt-2"><option value="">{{ __('Select the chair') }}</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</select></label>
        <label class="block font-bold">{{ __('Minutes author') }} <select wire:model="minutesTypedBy" class="block w-full rounded-xl border p-3 mt-2"><option value="">{{ __('Who wrote the minutes?') }}</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</select></label>
        <label class="block font-bold">{{ __('Session minutes') }} <textarea wire:model="minutes" rows="8" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="font-bold">{{ __('Outcome') }} <select wire:model="outcome" class="block w-full rounded-xl border p-3 mt-2"><option value="recommendation_only">{{ __('Recommendation without penalty') }}</option><option value="warning">{{ __('Warning') }}</option><option value="termination">{{ __('Termination') }}</option></select></label>
            <label class="font-bold">{{ __('Employee defense considered') }} <select wire:model="defenseAcceptance" class="block w-full rounded-xl border p-3 mt-2"><option value="accepted_fully">{{ __('Fully') }}</option><option value="accepted_partially">{{ __('Partially') }}</option><option value="rejected">{{ __('Not accepted') }}</option></select></label>
        </div>
        @foreach(['selected','chairId','minutesTypedBy','minutes'] as $field)@error($field)<p class="text-red text-sm">{{ $message }}</p>@enderror@endforeach
        <button wire:click="save" class="bg-primary text-white font-bold rounded-2xl px-6 py-3">{{ __('Save session') }}</button>
    </section>
</div>
