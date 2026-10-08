<div class="max-w-6xl mx-auto space-y-5">
    <a href="/committee" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Committee') }}</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">{{ __('Review committee recommendations') }}</h1>
        @if($document->disciplinaryCase->confirmedResponseText())<section class="bg-gray-50 rounded-2xl p-4"><h2 class="font-bold">{{ __('Transcribed text — the scan is the original') }}</h2><p class="whitespace-pre-wrap" dir="auto">{{ $document->disciplinaryCase->confirmedResponseText() }}</p></section>@endif
        <p class="text-gray-500">{{ __('Your decision covers every language shown. The text is read only.') }}</p>
        @if($notice)<p role="status" class="bg-red-light text-red rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        <div class="grid lg:grid-cols-3 gap-4">
            @foreach($document->contents as $content)
                <div class="bg-gray-50 rounded-2xl p-5" dir="{{ $content->language_code === 'en' ? 'ltr' : 'rtl' }}">
                    <h2 class="font-black">{{ ['ar'=>__('Arabic'),'en'=>__('English'),'ku'=>__('Kurdish')][$content->language_code] ?? $content->language_code }}</h2>
                    <h3 class="font-bold mt-4">{{ __('Findings') }}</h3><div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->body) !!}</div>
                    <h3 class="font-bold mt-4">{{ __('Recommendations') }}</h3><div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->secondary_body) !!}</div>
                </div>
            @endforeach
        </div>
        @if($categories->isEmpty())<p role="status" class="bg-gray-50 rounded-2xl p-4">{{ __('Your decision was recorded or your category has approved. The recommendations are read only.') }}</p>
        @else
            <label class="block font-bold">{{ __('Approval category') }} <select wire:model="category" class="block rounded-xl border p-3 mt-2"><option value="">{{ __('Select category') }}</option>@foreach($categories as $role)<option value="{{ $role->value }}">{{ ['legal'=>__('Legal'),'hr'=>__('HR'),'labor_union'=>__('Labor union')][$role->value] }}</option>@endforeach</select></label>
            <label class="block font-bold">{{ __('Rejection comment') }} <textarea wire:model="comment" rows="3" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            @error('comment')<p class="text-red">{{ $message }}</p>@enderror
            <div class="flex gap-3"><button wire:click="approve" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">{{ __('Approve') }}</button><button wire:click="reject" class="border border-red text-red rounded-2xl px-6 py-3">{{ __('Reject with comment') }}</button></div>
        @endif
    </section>
</div>
