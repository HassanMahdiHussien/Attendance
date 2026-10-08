<div class="max-w-5xl mx-auto space-y-6">
    <a href="/cases/{{ $record->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm">
        <div class="flex items-center gap-2 text-amber font-bold mb-5"><span class="w-2 h-2 rounded-full bg-amber"></span>{{ $document->is_locked ? __('Document issued') : __('Draft — awaiting your confirmation') }}</div>
        <h1 class="text-2xl font-black mb-5">{{ __('Confirm') }} {{ ['defense_letter' => __('Defense request letter'), 'recommendations' => __('Committee recommendations'), 'decision_letter' => __('Decision letter')][$document->document_type->value] }}</h1>
        @if($blockers)<div role="alert" class="bg-red-light text-red rounded-2xl p-4 mb-5"><strong>{{ __('Complete the following') }}:</strong><ul class="list-disc ps-6">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>@endif
        @if(!$document->is_locked && $document->document_type === \App\Enums\DocumentType::DecisionLetter && $document->penalty_type === \App\Enums\PenaltyType::Termination && !$document->leave_confirmed_at)<label class="flex gap-2 items-center mb-3"><input type="checkbox" wire:model="leaveChecked">{{ __('The employee is not on statutory leave or documented sick absence') }}</label><button wire:click="confirmLeave" class="border border-primary text-primary rounded-2xl px-5 py-3 mb-5">{{ __('Record confirmation') }}</button>@error('leaveChecked')<p class="text-red">{{ $message }}</p>@enderror@endif
        @if($notice)<p role="status" class="bg-primary-light text-primary rounded-2xl p-4 mb-5">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4 mb-5">{{ $message }}</p>@enderror
        @include('livewire.cases.partials.citation-review')
        @if($document->drafted_by === \App\Enums\DraftedBy::Ai)
            <p class="rounded-2xl bg-amber-light text-amber p-4 mb-5">{{ __('Check AI wording and references before issuance. The outcome keyword check is a review flag, not a guarantee of a correct decision.') }}</p>
            @if($notes = $document->aiDraftNotes()->latest('id')->first())
                <aside class="rounded-2xl bg-gray-50 p-4 mb-5"><h2 class="font-bold">{{ __('Drafting notes') }}</h2><ul class="list-disc ps-5">@foreach($notes->notes ?? [] as $note)<li>{{ $note }}</li>@endforeach</ul></aside>
            @endif
        @endif
        <div class="bg-gray-50 rounded-2xl p-4 md:p-6 mb-5" style="font-family: Amiri, serif">
            @if($document->is_locked)
                <p class="mb-3">{{ __('Reference number') }}: {{ \App\Support\Format\Ui::number($document->reference_number) }}</p>
                @if($document->pdf_status === 'ready')<a href="{{ route('documents.pdf', $document) }}" class="text-primary font-bold">{{ __('Download PDF') }}</a>
                @elseif($document->pdf_status === 'failed')<p class="text-red">{{ __('PDF preparation failed.') }}</p>
                @else<p class="text-amber">{{ __('PDF is being prepared.') }}</p>@endif
            @else
                <iframe title="{{ __('Document preview') }}" sandbox="" src="{{ route('documents.preview', $document) }}" class="w-full h-[650px] bg-white rounded-xl border border-gray-200"></iframe>
            @endif
        </div>
        @unless($document->is_locked)
            <label for="reference" class="block font-bold mb-2">{{ __('Reference number') }}</label>
            <input id="reference" wire:model.live="reference" dir="ltr" class="w-full md:w-64 rounded-2xl border border-gray-200 p-3 font-mono mb-4">
            @error('reference')<p class="text-red text-sm">{{ $message }}</p>@enderror
            @if($record->company->requires_second_review)
                <div class="bg-primary-light rounded-2xl p-4 mb-5 text-sm">
                    @if($document->second_reviewed_by){{ __('Another user reviewed this document.') }}
                    @else{{ __('The company requires another user to review before issuance.') }} <button wire:click="markReviewed" class="text-primary font-bold underline">{{ __('Record second review') }}</button>@endif
                </div>
            @endif
            <div class="bg-amber-light text-amber rounded-2xl p-4 mb-5">⚠ {{ __('Check names and dates — the document cannot be edited after issuance') }}</div>
            <div class="flex flex-wrap gap-3">
                @unless($blockers)<button wire:click="issue" class="bg-primary text-white font-bold rounded-2xl px-6 py-3 shadow-lg shadow-indigo-200">✓ {{ __('Confirm and issue') }}</button>@endunless
                @unless($record->status === \App\Enums\CaseStatus::AwaitingExternalLegalConsultation)<button wire:click="sendBack" class="border border-gray-200 rounded-2xl px-6 py-3">{{ __('Edit') }}</button>@endunless
            </div>
        @endunless
    </div>
</div>
