<div class="mx-auto max-w-5xl space-y-5">
    <a href="/cases/{{ $record->id }}" class="ui-link inline-flex items-center gap-1 text-sm"><x-ui.icon name="arrow" class="h-4 w-4 rtl:rotate-180" />{{ __('Case') }}</a>
    <x-ui.card>
        <x-ui.badge :tone="$document->is_locked ? 'green' : 'amber'">{{ $document->is_locked ? __('Document issued') : __('Draft — awaiting your confirmation') }}</x-ui.badge>
        <h1 class="my-5 text-2xl font-extrabold">{{ __('Confirm') }} {{ ['defense_letter' => __('Defense request letter'), 'recommendations' => __('Committee recommendations'), 'decision_letter' => __('Decision letter')][$document->document_type->value] }}</h1>
        @if($blockers)
            <x-ui.alert tone="red" class="mb-5"><strong>{{ __('Complete the following') }}:</strong><ul class="list-disc ps-6">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></x-ui.alert>
        @endif
        @if(! $document->is_locked && $document->document_type === \App\Enums\DocumentType::DecisionLetter && $document->penalty_type === \App\Enums\PenaltyType::Termination && ! $document->leave_confirmed_at)
            <div class="mb-5 space-y-3"><label class="flex items-start gap-2"><input type="checkbox" wire:model="leaveChecked" class="mt-1">{{ __('The employee is not on statutory leave or documented sick absence') }}</label><x-ui.button variant="secondary" wire:click="confirmLeave">{{ __('Record confirmation') }}</x-ui.button>@error('leaveChecked')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror</div>
        @endif
        @if($notice)<x-ui.alert tone="indigo" role="status" class="mb-5">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red" class="mb-5">{{ $message }}</x-ui.alert>@enderror
        @include('livewire.cases.partials.citation-review')
        @include('livewire.cases.partials.regulation-comparison-note')
        @if($document->drafted_by === \App\Enums\DraftedBy::Ai)
            <x-ui.alert tone="amber" class="mb-5">{{ __('Check AI wording and references before issuance. The outcome keyword check is a review flag, not a guarantee of a correct decision.') }}</x-ui.alert>
            @if($notes = $document->aiDraftNotes()->latest('id')->first())
                <aside class="mb-5 rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">{{ __('Drafting notes') }}</h2><ul class="list-disc ps-5">@foreach($notes->notes ?? [] as $note)<li>{{ is_array($note) ? __($note['key'], $note['params'] ?? []) : $note }}</li>@endforeach</ul></aside>
            @endif
        @endif
        <div class="mb-5 rounded-2xl border border-gray-200 bg-gray-50 p-4 md:p-6">
            <div class="font-amiri text-lg">
                @if($document->is_locked)
                    <p class="mb-3">{{ __('Reference number') }}: <bdi class="font-mono">{{ \App\Support\Format\Ui::number($document->reference_number) }}</bdi></p>
                    @if($document->pdf_status === 'ready')<a href="{{ route('documents.pdf', $document) }}" class="ui-link font-bold">{{ __('Download PDF') }}</a>
                    @elseif($document->pdf_status === 'failed')<p class="text-red-700">{{ __('PDF preparation failed.') }}</p>
                    @else<p class="text-amber-700">{{ __('PDF is being prepared.') }}</p>@endif
                @else
                    <iframe title="{{ __('Document preview') }}" sandbox="" src="{{ route('documents.preview', $document) }}" class="h-[650px] w-full rounded-xl border border-gray-200 bg-white"></iframe>
                @endif
            </div>
        </div>
        @unless($document->is_locked)
            <x-ui.field :label="__('Reference number')" for="reference" class="mb-4 max-w-xs"><x-ui.input id="reference" wire:model.live="reference" dir="ltr" class="font-mono" />@error('reference')<p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror</x-ui.field>
            @if($record->company->requires_second_review)
                <x-ui.alert tone="indigo" class="mb-5">
                    @if($document->second_reviewed_by){{ __('Another user reviewed this document.') }}
                    @else{{ __('The company requires another user to review before issuance.') }} <x-ui.button variant="ghost" wire:click="markReviewed">{{ __('Record second review') }}</x-ui.button>@endif
                </x-ui.alert>
            @endif
            <x-ui.alert tone="amber" class="mb-5 flex items-center gap-2"><x-ui.icon name="alert" class="h-5 w-5" />{{ __('Check names and dates — the document cannot be edited after issuance') }}</x-ui.alert>
            <div class="flex flex-wrap gap-3">
                @unless($blockers)<x-ui.button wire:click="issue"><x-ui.icon name="check" class="h-4 w-4" />{{ __('Confirm and issue') }}</x-ui.button>@endunless
                @unless($record->status === \App\Enums\CaseStatus::AwaitingExternalLegalConsultation)<x-ui.button variant="secondary" wire:click="sendBack">{{ __('Edit') }}</x-ui.button>@endunless
            </div>
        @endunless
    </x-ui.card>
</div>
