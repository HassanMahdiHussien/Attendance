<div class="mx-auto max-w-5xl space-y-5">
    <x-ui.page-header :title="__('Write decision letter')" :subtitle="__('Write the reasons for the committee’s decision. The outcome and penalty are printed from the case record.')" back="/cases/{{ $case->id }}" />
    <x-ui.card class="space-y-5">
        @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        @include('livewire.cases.partials.ai-editor-controls')
        @include('livewire.cases.partials.citation-review')
        @foreach($languages as $language)
            <label class="block font-bold" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}">{{ ['ar' => __('Arabic'), 'en' => __('English'), 'ku' => __('Kurdish')][$language] ?? $language }}
                <x-ui.textarea wire:model="reasons.{{ $language }}" rows="8" class="mt-2 font-amiri text-lg" />
            </label>
            @error('reasons.'.$language)<p class="text-red-700">{{ $message }}</p>@enderror
        @endforeach
        @if($case->outcome?->value === 'termination')
            @unless($document?->leave_confirmed_at)<label class="flex items-start gap-2"><input type="checkbox" wire:model="leaveChecked" class="mt-1">{{ __('The employee is not on statutory leave or documented sick absence') }}</label><x-ui.button variant="secondary" wire:click="confirmLeave">{{ __('Record confirmation') }}</x-ui.button>@error('leaveChecked')<p class="text-red-700">{{ $message }}</p>@enderror@endunless
            @if($document?->leave_confirmed_at)<x-ui.alert tone="green" role="status">{{ __('Confirmation recorded') }} {{ \App\Support\Format\Ui::date($document->leave_confirmed_at) }}</x-ui.alert>@endif
        @endif
        <div class="flex flex-wrap gap-3"><x-ui.button variant="secondary" wire:click="save">{{ __('Save draft') }}</x-ui.button><x-ui.button wire:click="submit">{{ __('Submit for review') }}</x-ui.button></div>
    </x-ui.card>
</div>
