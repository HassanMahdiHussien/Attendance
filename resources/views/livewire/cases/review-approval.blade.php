<div class="mx-auto max-w-6xl space-y-5">
    <x-ui.page-header :title="__('Review committee recommendations')" :subtitle="__('Your decision covers every language shown. The text is read only.')" back="/committee" />
    <x-ui.card class="space-y-5">
        @if($document->disciplinaryCase->confirmedResponseText())<section class="rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">{{ __('Transcribed text — the scan is the original') }}</h2><p class="whitespace-pre-wrap" dir="auto">{{ $document->disciplinaryCase->confirmedResponseText() }}</p></section>@endif
        @if($notice)<x-ui.alert tone="red" role="status">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach($document->contents as $content)
                <div class="rounded-2xl bg-gray-50 p-5" dir="{{ $content->language_code === 'en' ? 'ltr' : 'rtl' }}">
                    <h2 class="font-extrabold">{{ ['ar' => __('Arabic'), 'en' => __('English'), 'ku' => __('Kurdish')][$content->language_code] ?? $content->language_code }}</h2>
                    <h3 class="mt-4 font-bold">{{ __('Findings') }}</h3><div class="body font-amiri text-lg">{!! app(\App\Documents\BodyFormatter::class)->format($content->body) !!}</div>
                    <h3 class="mt-4 font-bold">{{ __('Recommendations') }}</h3><div class="body font-amiri text-lg">{!! app(\App\Documents\BodyFormatter::class)->format($content->secondary_body) !!}</div>
                </div>
            @endforeach
        </div>
        @if($categories->isEmpty())<x-ui.alert tone="indigo" role="status">{{ __('Your decision was recorded or your category has approved. The recommendations are read only.') }}</x-ui.alert>
        @else
            <label class="block font-bold">{{ __('Approval category') }}<x-ui.select wire:model="category" class="mt-2"><option value="">{{ __('Select category') }}</option>@foreach($categories as $role)<option value="{{ $role->value }}">{{ ['legal' => __('Legal'), 'hr' => __('HR'), 'labor_union' => __('Labor union')][$role->value] }}</option>@endforeach</x-ui.select></label>
            <label class="block font-bold">{{ __('Rejection comment') }}<x-ui.textarea wire:model="comment" rows="3" class="mt-2" /></label>
            @error('comment')<p class="text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3"><x-ui.button wire:click="approve">{{ __('Approve') }}</x-ui.button><x-ui.button variant="danger" wire:click="reject">{{ __('Reject with comment') }}</x-ui.button></div>
        @endif
    </x-ui.card>
</div>
