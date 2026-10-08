<div class="mx-auto max-w-5xl">
    <x-ui.page-header :title="__('Committee')" :subtitle="__('Recommendations are waiting for your review. The review shows every enabled language.')" />
    @if(session('committee_notice'))<x-ui.alert tone="green" class="mb-5" role="status">{{ session('committee_notice') }}</x-ui.alert>@endif
    <x-ui.card padding="none">
        @forelse($documents as $document)
            <a href="/committee/approvals/{{ $document->id }}" class="flex min-h-16 items-center gap-3 border-b border-[#EEF0F4] px-5 py-4 font-bold text-primary hover:bg-[#FAFAFC] focus-visible:outline-2 focus-visible:outline-primary">
                <x-ui.icon name="file" class="h-5 w-5" />
                <span class="min-w-0 flex-1">{{ __('Case') }} <bdi class="font-mono">{{ \App\Support\Format\Ui::number($document->disciplinaryCase->case_number) }}</bdi> — {{ __('Committee recommendations') }}</span>
                <x-ui.icon name="chevron" class="h-4 w-4 rtl:rotate-180" />
            </a>
        @empty
            <x-ui.empty :title="__('No approvals are waiting for you.')" icon="check" />
        @endforelse
    </x-ui.card>
</div>
