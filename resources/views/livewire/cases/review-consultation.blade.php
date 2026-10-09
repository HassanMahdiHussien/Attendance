<div class="mx-auto max-w-6xl space-y-5">
    <x-ui.page-header :title="__('Legal consultation').' — '.__('Case').' '.\App\Support\Format\Ui::number($case->case_number)" back="/my-cases" />
    <x-ui.card class="space-y-5">
        @if($case->confirmedResponseText())<section class="rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">{{ __('Transcribed text — the scan is the original') }}</h2><p class="whitespace-pre-wrap" dir="auto">{{ $case->confirmedResponseText() }}</p></section>@endif
        <p>{{ $case->employee?->name }} — {{ \App\Support\Format\Violation::name($case) }}</p>
        <section><h2 class="font-bold">{{ __('Committee session minutes') }}</h2><p class="whitespace-pre-wrap">{{ $case->committee_session_notes }}</p></section>
        @foreach($case->documents as $document)
            <section class="border-t border-[#EEF0F4] pt-4">
                @include('livewire.cases.partials.regulation-comparison-note')
                <h2 class="font-bold">{{ ['defense_letter' => __('Defense request letter'), 'recommendations' => __('Committee recommendations'), 'decision_letter' => __('Decision letter')][$document->document_type->value] }}</h2>
                @if($document->pdf_status === 'ready')<a href="{{ route('documents.pdf', $document) }}" class="ui-link">{{ __('Download issued document') }}</a>@endif
                @foreach($document->contents as $content)
                    <div dir="{{ $content->language_code === 'en' ? 'ltr' : 'rtl' }}" class="my-3 rounded-2xl bg-gray-50 p-4 font-amiri text-lg"><strong>{{ ['ar' => __('Arabic'), 'en' => __('English'), 'ku' => __('Kurdish')][$content->language_code] ?? $content->language_code }}</strong><div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->body) !!}</div>@if($content->secondary_body)<div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->secondary_body) !!}</div>@endif</div>
                @endforeach
            </section>
        @endforeach
        <section><h2 class="font-bold">{{ __('Attachments and scans') }}</h2>@foreach($attachments as $attachment)<a href="{{ route('attachments.download', $attachment) }}" class="ui-link block">{{ $attachment->original_name }}</a>@endforeach</section>
        @if($notice)<x-ui.alert tone="green" role="status">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        @if(!$case->legal_review_completed_at && $case->status !== \App\Enums\CaseStatus::ArchivedFinal)
            <label class="block font-bold">{{ __('Consultation note') }}<x-ui.textarea wire:model="note" rows="7" class="mt-2" /></label>
            @error('note')<p class="text-red-700">{{ $message }}</p>@enderror
            <x-ui.button wire:click="complete">{{ __('Complete consultation') }}</x-ui.button>
        @else<p class="text-gray-500">{{ __('Consultation completed.') }} {{ $case->legal_review_note }}</p>@endif
    </x-ui.card>
</div>
