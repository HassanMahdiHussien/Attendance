<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">{{ __('Write decision letter') }}</h1>
        <p class="text-gray-500">{{ __('Write the reasons for the committee’s decision. The outcome and penalty are printed from the case record.') }}</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="text-red">{{ $message }}</p>@enderror
        @include('livewire.cases.partials.ai-editor-controls')
        @include('livewire.cases.partials.citation-review')
        @foreach($languages as $language)
            <label class="block font-bold" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}">{{ ['ar'=>__('Arabic'),'en'=>__('English'),'ku'=>__('Kurdish')][$language] ?? $language }}
                <textarea wire:model="reasons.{{ $language }}" rows="8" class="block w-full rounded-xl border p-3 mt-2"></textarea>
            </label>
            @error('reasons.'.$language)<p class="text-red">{{ $message }}</p>@enderror
        @endforeach
        @if($case->outcome?->value === 'termination')
            @unless($document?->leave_confirmed_at)<label class="flex gap-2 items-center"><input type="checkbox" wire:model="leaveChecked">{{ __('The employee is not on statutory leave or documented sick absence') }}</label><button wire:click="confirmLeave" class="border border-primary text-primary rounded-2xl px-5 py-3">{{ __('Record confirmation') }}</button>@error('leaveChecked')<p class="text-red">{{ $message }}</p>@enderror@endunless
            @if($document?->leave_confirmed_at)<p role="status" class="text-green">{{ __('Confirmation recorded') }} {{ \App\Support\Format\Ui::date($document->leave_confirmed_at) }}</p>@endif
        @endif
        <div class="flex gap-3"><button wire:click="save" class="border border-primary text-primary rounded-2xl px-5 py-3">{{ __('Save draft') }}</button><button wire:click="submit" class="bg-primary text-white rounded-2xl px-5 py-3">{{ __('Submit for review') }}</button></div>
    </section>
</div>
