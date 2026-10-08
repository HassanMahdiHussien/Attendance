<div class="max-w-5xl mx-auto space-y-5">
    <h1 class="text-2xl font-black">{{ __('Committee') }}</h1>
    @if(session('committee_notice'))<p role="status" class="bg-green-light text-green rounded-2xl p-4">{{ session('committee_notice') }}</p>@endif
    <p class="text-gray-500">{{ __('Recommendations are waiting for your review. The review shows every enabled language.') }}</p>
    <section class="bg-white rounded-3xl p-6 shadow-sm">
        @forelse($documents as $document)
            <a href="/committee/approvals/{{ $document->id }}" class="block border-b py-4 text-primary font-bold">{{ __('Case') }} {{ \App\Support\Format\Ui::number($document->disciplinaryCase->case_number) }} — {{ __('Committee recommendations') }}</a>
        @empty
            <p class="text-gray-500">{{ __('No approvals are waiting for you.') }}</p>
        @endforelse
    </section>
</div>
