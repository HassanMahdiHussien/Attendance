<div class="space-y-5">
    @php
        $canManage = auth()->user()->can('update', $record);
        $step = \App\Support\Format\StageIndicator::step($record->status);
        $documentTypes = ['defense_letter' => __('Defense request letter'), 'recommendations' => __('Committee recommendations'), 'decision_letter' => __('Decision letter')];
        $documentStatuses = ['draft' => __('Draft'), 'ready_for_review' => __('Awaiting review'), 'issued' => __('Issued'), 'superseded' => __('Superseded')];
        $colors = ['met'=>'ui-badge-green','on_track'=>'ui-badge-indigo','approaching'=>'ui-badge-amber','missed'=>'ui-badge-red','overdue'=>'ui-badge-red'];
    @endphp
    <a href="/cases" class="ui-link inline-flex items-center gap-1 text-sm"><x-ui.icon name="arrow" class="h-4 w-4 rtl:rotate-180" />{{ __('Cases board') }}</a>
    @if($success)<p role="status" class="rounded-2xl bg-green-light p-4 text-green-700">{{ $success }}</p>
@endif
    @if($notice)<p role="status" class="rounded-2xl bg-amber-light p-4 text-amber-700">{{ $notice }}</p>
@endif
    @if($record->legal_review_requested_at && !$record->legal_review_completed_at && !$record->legal_review_cancelled_at)
        <div role="alert" class="bg-amber-light text-amber rounded-2xl p-5 font-bold">{{ __('Legal consultation pending since :date. The 15-day procedure deadline continues; due by :due.', ['date' => \App\Support\Format\Ui::date($record->legal_review_requested_at), 'due' => $deadlines->procedureDue($record) ? \App\Support\Format\Ui::date($deadlines->procedureDue($record)) : __('Deadline start')]) }}</div>
    @endif
    @if($record->legal_review_completed_at)<div class="bg-green-light text-green rounded-2xl p-5">
<strong>{{ __('Legal consultation completed') }}</strong>
<p class="whitespace-pre-wrap mt-2">{{ $record->legal_review_note }}</p>
</div>
@endif
    @if($record->minutes_typed_by_pool_member_id)<p class="ui-card p-4 text-sm">{{ __('Session minutes written by: :name', ['name' => \App\Models\CommitteePoolMember::query()->find($record->minutes_typed_by_pool_member_id)?->person_name]) }}</p>
@endif
    @if($record->status === \App\Enums\CaseStatus::AwaitingCommitteeSignatures)
        @foreach($signatureChecks->where('presence_check', \App\Enums\PresenceCheck::BoxAppearsEmpty) as $check)
            <p role="alert" class="rounded-2xl bg-red-light p-4 text-red-700">{{ $check->signer_name }} — {{ \App\Services\Cases\PresenceWording::choice('empty') }}. {{ __('Upload a new scan or record an override reason.') }}</p>
        @endforeach
    @endif
    @foreach($decisionSignatureChecks as $check)<p class="bg-gray-50 rounded-2xl p-4">{{ $check->signer_name }} — {{ \App\Services\Cases\PresenceWording::choice($check->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty ? 'empty' : 'filled') }}. {{ __('This checks presence only.') }}</p>
@endforeach
    @foreach($record->documents->where('document_type', \App\Enums\DocumentType::Recommendations) as $recommendationsDocument)
        @foreach($recommendationsDocument->approvals()->where('decision', 'rejected')->whereNull('invalidated_at')->get() as $rejection)
            <p role="alert" class="rounded-2xl bg-red-light p-4 text-red-700">{{ __('Recommendations returned by the committee: :comment', ['comment' => $rejection->comment]) }}</p>
        @endforeach
    @endforeach
    @error('notice')<p role="alert" class="rounded-2xl bg-red-light p-4 text-red-700">{{ $message }}</p>
@enderror
    <header class="ui-card p-5">
<div class="flex flex-wrap justify-between gap-4">
<div>
<p class="font-mono text-gray-500 text-sm">{{ __('Case') }} <bdi>{{ \App\Support\Format\Ui::number(str_pad($record->case_number, 4, '0', STR_PAD_LEFT)) }}</bdi>
</p>
<h1 class="mt-2 text-2xl font-extrabold text-ink">{{ $record->employee?->name }}</h1>
@if($record->employee && app(\App\Policies\EmployeeProfilePolicy::class)->view(auth()->user(), $record->employee))<a class="ui-link inline-block text-sm font-bold" href="{{ route('employees.show', $record->employee) }}">{{ __('Employee page') }}</a>@endif
<p class="text-gray-500">{{ $record->violation_type }} — {{ $record->employee?->position }} / {{ $record->employee?->department }}</p>
</div>
<x-ui.badge :tone="\App\Support\Format\Tone::case($record->status)" class="self-start">{{ \App\Support\Format\Ui::stage($record->status) }}</x-ui.badge>
</div>
<x-ui.stepper :step="$step" class="mt-5" />
</header>
    @if($nextAction)
        @php
            $nextActorLabel = match ($nextAction->actor) {
                'hr' => __('messages.search.actor_hr'),
                'committee' => __('messages.search.actor_committee'),
                'reviewer' => __('messages.search.actor_reviewer'),
                'optional' => __('messages.search.actor_optional'),
                default => __('messages.search.actor_waiting'),
            };
        @endphp
        <x-ui.card class="border border-primary/30 bg-primary-light/30 p-5" aria-label="{{ __('messages.search.next_step') }}">
            <p class="text-xs font-black uppercase tracking-wide text-primary">{{ __('messages.search.next_step') }}</p>
            <h2 class="mt-2 text-lg font-black">{{ $nextAction->label }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ $nextActorLabel }}</p>
            @if($nextAction->due)
                <div class="mt-3 flex items-center gap-2 text-sm"><span>{{ __('messages.search.due') }} {{ \App\Support\Format\Ui::date($nextAction->due) }}</span>
                    @if($nextAction->deadlineAlert)<x-ui.badge :tone="$nextAction->deadlineAlert">{{ $nextAction->deadlineAlert === 'red' ? __('Overdue') : __('Approaching') }}</x-ui.badge>@endif
                </div>
            @endif
            @if($nextAction->target)<x-ui.button :href="$nextAction->target" class="mt-4">{{ __('messages.search.open_step') }}</x-ui.button>@endif
            @if($nextAction->key === 'waiting_response')<x-ui.button href="#next-handoff" variant="secondary" class="mt-4">{{ __('messages.home.record_response') }}</x-ui.button>@endif
        </x-ui.card>
    @endif
    @if($priors['count'])<section class="bg-amber-light rounded-2xl p-5">
<h2 class="font-bold">{{ __('Prior record') }} — {{ \App\Support\Format\Ui::number($priors['count']) }}</h2>
@foreach($priors['cases'] as $prior)<p class="text-sm mt-2 {{ $prior['same_type_repeat'] ? 'text-red font-bold' : '' }}">
<a href="/cases/{{ $prior['case_id'] }}" class="ui-link">{{ \App\Support\Format\Ui::date($prior['date']) }} — {{ $prior['violation_type'] }} — {{ \App\Support\Format\Ui::outcome($prior['outcome'] ?? null) }}</a>@if($prior['same_type_repeat']) · {{ __('Same violation type repeated within 12 months') }}@endif</p>
@endforeach</section>
@endif
    @if($relatedCases->isNotEmpty())
        <p class="rounded-2xl bg-primary-light p-4 text-sm font-bold">{{ trans_choice('messages.related.count', $relatedCases->count(), ['count' => \App\Support\Format\Ui::number($relatedCases->count())]) }}</p>
    @endif
    <x-ui.card class="p-5">
        <h2 class="text-lg font-black">{{ __('messages.related.title') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('messages.related.internal_only') }}</p>
        @forelse($relatedCases as $related)
            @php $other = $related['case']; @endphp
            <div class="mt-4 border-t border-gray-200 pt-4">
                <div class="flex flex-wrap items-center gap-2">
                    <a class="ui-link font-bold" href="/cases/{{ $other->id }}">{{ __('messages.related.case_number', ['number' => \App\Support\Format\Ui::number($other->case_number)]) }} — {{ $other->employee?->name }}</a>
                    <x-ui.badge tone="gray">{{ \App\Support\Format\Ui::outcome($other->outcome?->value) }}</x-ui.badge>
                </div>
                <p class="mt-1 text-sm text-gray-600">{{ \App\Support\Format\Ui::date($other->violation_time) }} · {{ $other->violation_type }}</p>
                @if($related['link']->reason)<p class="mt-1 text-sm">{{ $related['link']->reason }}</p>@endif
                @if($related['in_error'])
                    <p class="mt-2 text-sm text-red-700">{{ __('messages.related.in_error', ['reason' => $related['in_error']->reason]) }}</p>
                @elseif($canLinkRelated)
                    <div class="mt-2 flex flex-wrap items-end gap-2">
                        <label class="text-sm">{{ __('messages.related.error_reason') }}
                            <x-ui.input wire:model="relatedErrorReasons.{{ $related['link']->id }}" maxlength="500" class="block" />
                        </label>
                        <x-ui.button variant="secondary" wire:click="markRelatedInError({{ $related['link']->id }})">{{ __('messages.related.mark_in_error') }}</x-ui.button>
                    </div>
                    @error('relatedErrorReasons.'.$related['link']->id)<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                @endif
            </div>
        @empty
            <p class="mt-3 text-sm text-gray-500">{{ __('messages.related.none') }}</p>
        @endforelse
        @if($canLinkRelated)
            <div class="mt-5 border-t border-gray-200 pt-4">
                <h3 class="font-bold">{{ __('messages.related.add') }}</h3>
                <label class="mt-3 block text-sm">{{ __('messages.related.search') }}
                    <x-ui.input wire:model.live.debounce.300ms="relatedSearch" class="mt-1 block w-full" />
                </label>
                <label class="mt-3 block text-sm">{{ __('messages.related.choose') }}
                    <x-ui.select wire:model="relatedCaseId" class="mt-1 block w-full">
                        <option value="">{{ __('messages.related.choose_placeholder') }}</option>
                        @foreach($relatedCandidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ \App\Support\Format\Ui::number($candidate->case_number) }} — {{ $candidate->employee?->name }}</option>
                        @endforeach
                    </x-ui.select>
                </label>
                <label class="mt-3 block text-sm">{{ __('messages.related.reason_optional') }}
                    <x-ui.input wire:model="relatedReason" maxlength="500" class="mt-1 block w-full" />
                </label>
                <x-ui.button class="mt-3" wire:click="linkRelated">{{ __('messages.related.link') }}</x-ui.button>
                @error('relatedCaseId')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                @error('relatedReason')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endif
    </x-ui.card>
    @if($pattern['flagged'])<div class="bg-amber-light text-amber rounded-2xl p-5">{{ __('The alleged responsible person was named in :count other branch cases in the past year.', ['count' => \App\Support\Format\Ui::number($pattern['count'])]) }}</div>
@endif
    @foreach($conflicts as $conflict)<div class="bg-red-light rounded-2xl p-5">
<strong class="text-red">{{ __('Committee conflict: :name', ['name' => $conflict['member']->person_name]) }}</strong>
<p class="text-sm">{{ $conflict['type'] === 'employee_linked' ? __('Direct link to a case party — a replacement is required.') : ($conflict['excluded'] ? __('A name match requires HR identity verification.') : __('The name match was verified.')) }}</p>
@if($canManage && $conflict['type'] === 'name_only' && $conflict['excluded'])<input wire:model="clearanceReason" placeholder="{{ __('Identity verification reason') }}" class="ui-control rounded-xl border p-2 mt-2">
<x-ui.button variant="secondary" wire:click="clearConflict({{ $conflict['member']->id }})" class="text-primary font-bold px-3">{{ __('Record verification') }}</x-ui.button>
@error('clearanceReason')<p class="text-red">{{ $message }}</p>
@enderror@endif</div>
@endforeach
    @if(in_array($deadlines->procedureStatus($record), ['approaching','overdue'], true))<div role="alert" class="bg-red-light text-red rounded-2xl p-5 font-bold">{{ __('Alert: the 15-day procedure deadline :state.', ['state' => $deadlines->procedureStatus($record) === 'overdue' ? __('has passed') : __('is approaching')]) }}</div>
@endif
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
<section id="next-actions" class="ui-card scroll-mt-20 p-5 lg:col-span-2">
<h2 class="text-lg font-black mb-4">{{ __('Actions') }}</h2>
        @if($record->status !== \App\Enums\CaseStatus::ArchivedFinal && !$record->legal_review_requested_at && in_array(auth()->user()->primary_role, [\App\Enums\UserRole::CompanyAdmin, \App\Enums\UserRole::HrStaff, \App\Enums\UserRole::CommitteeMember], true))<span id="next-consultation" class="scroll-mt-20"></span><x-ui.button variant="secondary" wire:click="requestConsultation" class="mb-4 border-amber-300 text-amber-700">{{ __('Request legal consultation') }}</x-ui.button>
@endif
        @if(auth()->user()->primary_role === \App\Enums\UserRole::ExternalLegalReviewer && $record->legal_review_requested_at)<x-ui.button href="/cases/{{ $record->id }}/consultation" variant="secondary">{{ __('Review consultation') }}</x-ui.button>@endif
        @if($canManage)
            @if($record->status === \App\Enums\CaseStatus::SignedRecommendationsUploaded && $record->outcome?->value === 'recommendation_only')
                @if($record->legal_review_requested_at && !$record->legal_review_completed_at && !$record->legal_review_cancelled_at && auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)<label class="block mt-3">{{ __('Reason to cancel consultation') }} <input wire:model="consultationCancelReason" class="ui-control rounded-xl border p-2">
</label>
<x-ui.button variant="danger" wire:click="cancelConsultation">{{ __('Cancel consultation') }}</x-ui.button>
@error('consultationCancelReason')<p class="text-red">{{ $message }}</p>
@enderror@endif
                @if(!$record->legal_review_requested_at || $record->legal_review_completed_at || $record->legal_review_cancelled_at)<x-ui.button variant="primary" wire:click="closeWithoutPenalty" class="bg-primary text-white rounded-2xl px-5 py-3">{{ __('Archive without a penalty') }}</x-ui.button>
@endif
            @endif
            @if($record->status === \App\Enums\CaseStatus::SignedRecommendationsUploaded && in_array($record->outcome?->value, ['warning', 'termination'], true))<x-ui.button href="/cases/{{ $record->id }}/decision">{{ __('Write decision letter') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingExternalLegalConsultation, \App\Enums\CaseStatus::DecisionLetterReadyForReview], true))<x-ui.button href="/cases/{{ $record->id }}/documents/{{ $record->documents->where('document_type', \App\Enums\DocumentType::DecisionLetter)->last()?->id }}/confirm" variant="secondary">{{ __('Confirm decision letter') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingAppealWindow, \App\Enums\CaseStatus::AppealPending], true))<span id="next-appeal" class="scroll-mt-20"></span><x-ui.button href="/cases/{{ $record->id }}/appeal" variant="secondary">{{ __('Record appeal and outcome') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingEmployeeResponse, \App\Enums\CaseStatus::ResponseReceived, \App\Enums\CaseStatus::ResponseRefusedToSign, \App\Enums\CaseStatus::ResponseDeliveryRefused, \App\Enums\CaseStatus::ResponseScannedUploaded, \App\Enums\CaseStatus::AiIngestedResponse, \App\Enums\CaseStatus::CommitteeSessionHeld], true))<x-ui.button href="/cases/{{ $record->id }}/session" variant="secondary">{{ __('Record committee session') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::CommitteeSessionHeld, \App\Enums\CaseStatus::RecommendationsCitationCheck, \App\Enums\CaseStatus::RecommendationsAwaitingApprovals, \App\Enums\CaseStatus::RecommendationsConfirmed], true) && $record->session_majority_reached)<x-ui.button href="/cases/{{ $record->id }}/recommendations" variant="secondary">{{ __('Write recommendations') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::RecommendationsAwaitingApprovals, \App\Enums\CaseStatus::RecommendationsConfirmed], true))<x-ui.button href="/cases/{{ $record->id }}/documents/{{ $record->documents->where('document_type', \App\Enums\DocumentType::Recommendations)->last()?->id }}/confirm">{{ __('Review recommendation approvals') }}</x-ui.button>@endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingCommitteeSignatures, \App\Enums\CaseStatus::SignedRecommendationsUploaded], true))<x-ui.button href="/cases/{{ $record->id }}/signed-scan" variant="secondary">{{ __('Recommendations and signatures scan') }}</x-ui.button>@endif
            @if($record->status === \App\Enums\CaseStatus::Draft)<x-ui.button href="/cases/new?draft={{ $record->id }}" variant="secondary">{{ __('Edit facts') }}</x-ui.button>
<x-ui.button variant="primary" wire:click="open" class="bg-primary text-white rounded-2xl px-5 py-2">{{ __('Open case') }}</x-ui.button>
@endif
            @if($record->status === \App\Enums\CaseStatus::Open)<x-ui.button href="/cases/{{ $record->id }}/documents/{{ $record->documents->where('document_type', \App\Enums\DocumentType::DefenseLetter)->where('status', \App\Enums\DocumentStatus::Draft)->last()?->id ?? 'new' }}/edit">{{ __('Write defense request letter') }}</x-ui.button>@endif
            @if($record->status === \App\Enums\CaseStatus::DefenseLetterCitationCheck)<x-ui.button href="/cases/{{ $record->id }}/documents/{{ $record->documents->where('document_type', \App\Enums\DocumentType::DefenseLetter)->last()?->id }}/edit" variant="secondary">{{ __('Return to editing and check citations') }}</x-ui.button>@endif
            @if($record->status === \App\Enums\CaseStatus::DefenseLetterReadyForReview)<x-ui.button href="/cases/{{ $record->id }}/documents/{{ $record->documents->where('document_type', \App\Enums\DocumentType::DefenseLetter)->last()?->id }}/confirm">{{ __('Confirm document') }}</x-ui.button>@endif
            @if($record->status === \App\Enums\CaseStatus::AwaitingEmployeeResponse)
                <h3 id="next-handoff" class="scroll-mt-20 font-bold mt-5 mb-2">{{ __('Record paper handoff') }}</h3>
<div class="flex flex-wrap gap-2">
<x-ui.select wire:model="handoffPurpose" class="rounded-xl border p-2"><option value="defense_letter_receipt">{{ __('Defense letter receipt') }}</option><option value="response_submission">{{ __('Response submission') }}</option>
</x-ui.select>
<x-ui.select wire:model.live="handoffStatus" class="rounded-xl border p-2"><option value="signed">{{ __('Signed') }}</option><option value="refused_to_sign">{{ __('Received and refused to sign') }}</option><option value="delivery_refused">{{ __('Refused delivery') }}</option>
</x-ui.select>
</div>
                @if($handoffStatus === 'signed')<label class="block mt-2">{{ __('Signing date') }} <input type="datetime-local" wire:model="signedAt" class="ui-control rounded-xl border p-2">
</label>
@else<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
<input wire:model="witnessOne" placeholder="{{ __('First witness') }}" class="ui-control rounded-xl border p-2">
<input wire:model="witnessTwo" placeholder="{{ __('Second witness') }}" class="ui-control rounded-xl border p-2">
</div>
<label class="block mt-2">{{ __('Paper signed by both witnesses (PDF, JPG or PNG)') }}<input type="file" wire:model="witnessScan" accept=".pdf,.jpg,.jpeg,.png" class="ui-control block rounded-xl border p-2">
</label>
@if($handoffStatus === 'delivery_refused')<div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2">
<input type="datetime-local" wire:model="attemptedAt" aria-label="{{ __('Attempt date') }}" class="ui-control rounded-xl border p-2">
<input wire:model="attemptLocation" placeholder="{{ __('Attempt location') }}" class="ui-control rounded-xl border p-2">
<input wire:model="attemptedBy" placeholder="{{ __('Person making the attempt') }}" class="ui-control rounded-xl border p-2">
</div>
@endif @endif
                <x-ui.button variant="primary" wire:click="recordHandOff" class="bg-primary text-white rounded-2xl px-5 py-2 mt-3">{{ __('Record handoff') }}</x-ui.button>
@foreach(['signedAt','attemptedAt','attemptLocation','attemptedBy','witnessOne','witnessTwo','witnessScan'] as $field)@error($field)<p class="text-red text-sm">{{ $message }}</p>
@enderror@endforeach
                @if($deadlines->responseDue($record) && now('Asia/Baghdad')->startOfDay()->gt($deadlines->responseDue($record)) && !$record->events->contains('event_type', 'response_not_received'))<x-ui.button variant="danger" wire:click="markNoResponse" class="mt-4">{{ __('Record no response') }}</x-ui.button>
@endif
            @endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingAppealWindow, \App\Enums\CaseStatus::AppealPending], true))
                <div id="next-decision-signatures" class="scroll-mt-20 mt-5 border-t pt-4">
<h3 class="font-bold">{{ __('General Manager and union representative signature presence (optional)') }}</h3>
<label class="block">{{ __('General Manager') }} <x-ui.select wire:model="decisionSignaturePresence.general_manager" class="rounded-xl border p-2"><option value="filled">{{ __('Box filled') }}</option><option value="empty">{{ __('Box appears empty') }}</option>
</x-ui.select>
</label>
<label class="block">{{ __('Union representative') }} <x-ui.select wire:model="decisionSignaturePresence.union" class="rounded-xl border p-2"><option value="filled">{{ __('Box filled') }}</option><option value="empty">{{ __('Box appears empty') }}</option>
</x-ui.select>
</label>
<x-ui.button variant="secondary" wire:click="recordDecisionSignatures">{{ __('Record box states') }}</x-ui.button>
</div>
                <h3 id="next-handoff" class="scroll-mt-20 font-bold mt-5 mb-2">{{ __('Paper decision letter receipt') }}</h3>
@foreach($signatures->where('purpose', \App\Enums\SignaturePurpose::DecisionLetterReceipt) as $receipt)<p class="text-sm text-green mb-2">{{ __('Delivery attempt recorded: :status', ['status' => ['signed' => __('Signed'), 'refused_to_sign' => __('Received and refused to sign'), 'delivery_refused' => __('Refused delivery')][$receipt->status->value] ?? '']) }} — {{ \App\Support\Format\Ui::date($receipt->created_at) }}</p>
@endforeach<x-ui.select wire:model.live="handoffStatus" class="rounded-xl border p-2"><option value="signed">{{ __('Signed') }}</option><option value="refused_to_sign">{{ __('Received and refused to sign') }}</option><option value="delivery_refused">{{ __('Refused delivery') }}</option>
</x-ui.select>
                @if($handoffStatus === 'signed')<input type="datetime-local" wire:model="signedAt" aria-label="{{ __('Signing date') }}" class="ui-control rounded-xl border p-2">@else<input wire:model="witnessOne" placeholder="{{ __('First witness') }}" class="ui-control rounded-xl border p-2">
<input wire:model="witnessTwo" placeholder="{{ __('Second witness') }}" class="ui-control rounded-xl border p-2">
<label class="block">{{ __('Paper signed by both witnesses (PDF, JPG or PNG)') }}<input type="file" wire:model="witnessScan" accept=".pdf,.jpg,.jpeg,.png" class="ui-control block rounded-xl border p-2">
</label>
@if($handoffStatus === 'delivery_refused')<input type="datetime-local" wire:model="attemptedAt" aria-label="{{ __('Attempt date') }}" class="ui-control rounded-xl border p-2">
<input wire:model="attemptLocation" placeholder="{{ __('Attempt location') }}" class="ui-control rounded-xl border p-2">
<input wire:model="attemptedBy" placeholder="{{ __('Person making the attempt') }}" class="ui-control rounded-xl border p-2">@endif @endif
                <x-ui.button variant="primary" wire:click="recordHandOff" class="bg-primary text-white rounded-2xl px-5 py-2">{{ __('Record handoff') }}</x-ui.button>
                @foreach(['signedAt','attemptedAt','attemptLocation','attemptedBy','witnessOne','witnessTwo','witnessScan'] as $field)@error($field)<p role="alert" class="text-red text-sm">{{ $message }}</p>
@enderror@endforeach
            @endif
            @if(in_array($record->status, [\App\Enums\CaseStatus::AwaitingEmployeeResponse,\App\Enums\CaseStatus::ResponseReceived,\App\Enums\CaseStatus::ResponseRefusedToSign,\App\Enums\CaseStatus::ResponseDeliveryRefused], true))<div id="next-response-scan" class="scroll-mt-20 mt-5">
<label class="block font-bold mb-2">{{ __('Employee response scan') }}</label>
<input type="file" wire:model="responseScan" accept=".pdf,.jpg,.jpeg,.png" class="ui-control block mb-2">
<x-ui.button variant="secondary" wire:click="uploadResponse" class="border border-primary text-primary rounded-2xl px-5 py-2">{{ __('Upload scan') }}</x-ui.button>
@error('responseScan')<p class="text-red">{{ $message }}</p>
@enderror</div>
@endif
            @if($record->status === \App\Enums\CaseStatus::Open)<div class="mt-6 border-t pt-4">
<h3 class="font-bold mb-2">{{ __('Assigned HR employee') }}</h3>
<x-ui.select wire:model="assigneeId" class="rounded-xl border p-2"><option value="">{{ __('Unassigned') }}</option>@foreach($hrUsers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</x-ui.select>
<x-ui.button variant="secondary" wire:click="assign" class="text-primary font-bold px-3">{{ __('Save assignment') }}</x-ui.button>
@error('assigneeId')<p class="text-red">{{ $message }}</p>
@enderror</div>
@endif
        @else<p class="text-gray-500">{{ __('This case is read only.') }}</p>
@endif
        @if(in_array($record->status, [\App\Enums\CaseStatus::ResponseScannedUploaded, \App\Enums\CaseStatus::AiIngestedResponse], true) && ($responseScanAttachment || $record->confirmedResponseText()))
            <div id="next-response-scan" class="scroll-mt-20 mt-6 border-t pt-4 space-y-4">
                <h3 class="font-bold">{{ __('Employee response') }}</h3>
                @if($record->confirmedResponseText())
                    <p class="text-sm text-gray-500">{{ __('Transcribed text — the scanned image is the original') }}</p>
                    <p class="whitespace-pre-wrap" dir="auto">{{ $record->confirmedResponseText() }}</p>
                    <p class="text-sm text-gray-500">{{ __('Text confirmed by: :name', ['name' => $record->responseChecker?->name]) }}, {{ \App\Support\Format\Ui::date($record->employee_response_checked_at, 'Y/m/d H:i') }} · {{ $record->employee_response_origin === 'ai_checked' ? __('With AI assistance') : __('Manual entry') }}</p>
                    @if($canManage && $record->status === \App\Enums\CaseStatus::AiIngestedResponse)
                        <label>{{ __('Reason for text correction') }} <x-ui.textarea wire:model="responseCorrectionReason" class="block w-full rounded-xl border p-3">
</x-ui.textarea>
</label>
                        <x-ui.button variant="secondary" wire:click="correctResponse">{{ __('Correct text') }}</x-ui.button>
                        @error('responseCorrectionReason')<p class="text-red">{{ $message }}</p>
@enderror
                    @endif
                @endif
                @if($responseScanAttachment && $record->status === \App\Enums\CaseStatus::ResponseScannedUploaded && $canManage)
                    @if($record->ai_request_document_type === 'response_transcription')<p wire:poll.3s role="status">{{ __('Reading the response…') }}</p>
@endif
                    <label class="flex gap-2">
<input type="checkbox" wire:model="responseScanSafe">{{ __('I checked that the image contains no identity number, salary, or phone number') }}</label>
                    @error('responseScanSafe')<p class="text-red">{{ $message }}</p>
@enderror
                    <x-ui.button variant="secondary" wire:click="requestResponseReading" class="border border-primary text-primary rounded-2xl px-5 py-2">{{ __('Read response with AI') }}</x-ui.button>
                    @error('scan')<p class="text-red">{{ $message }}</p>
@enderror
                    @if($record->events->contains('event_type', 'response_reading_failed'))<p role="alert">{{ __('AI reading failed — you can enter the text manually') }}</p>
@endif
                    <div class="grid lg:grid-cols-2 gap-4">
                        <div>
<p class="text-sm text-gray-500">{{ __('The scanned image is the original') }}</p>
<object data="{{ route('attachments.download', $responseScanAttachment) }}?preview=1" type="{{ $responseScanAttachment->mime_type }}" class="w-full h-96 border rounded-xl">
<a class="ui-link" href="{{ route('attachments.download', $responseScanAttachment) }}">{{ __('Open scan for review') }}</a>
</object>
</div>
                        <div>
<label>{{ __('Transcribed text') }} <x-ui.textarea wire:model="responseText" rows="12" class="block w-full rounded-xl border p-3">
</x-ui.textarea>
</label>
                            <label>{{ __('Source') }} <x-ui.select wire:model="responseOrigin" class="rounded-xl border p-2"><option value="human">{{ __('Manual entry') }}</option>@if($aiAttempt)<option value="ai_checked">{{ __('AI reading after HR review') }}</option>@endif</x-ui.select>
</label>
                            <x-ui.button variant="primary" wire:click="confirmResponse" class="bg-primary text-white rounded-2xl px-5 py-2">{{ __('Confirm text after checking against the image') }}</x-ui.button>
                        </div>
                    </div>
                    @error('responseText')<p class="text-red">{{ $message }}</p>
@enderror
                @endif
            </div>
        @endif
    </section>
<section class="ui-card p-5">
<h2 class="text-lg font-black mb-4">{{ __('Deadlines') }}</h2>
@foreach([['title' => __('Response'),'due'=>$deadlines->responseDue($record),'state'=>$deadlines->responseStatus($record)],['title' => __('Procedure'),'due'=>$deadlines->procedureDue($record),'state'=>$deadlines->procedureStatus($record)],['title' => __('Appeal'),'due'=>$deadlines->appealEnd($record),'state'=>$deadlines->appealStatus($record)]] as $deadline)<div class="rounded-2xl border border-[#EEF0F4] p-4 mb-3 {{ $colors[$deadline['state']] ?? 'ui-badge-gray' }}">
<strong>{{ $deadline['title'] }}</strong>
<p class="text-sm">{{ $deadline['due'] ? \App\Support\Format\Ui::date($deadline['due']) : __('Not started yet') }}</p>
<p class="text-xs">{{ ['met' => __('Completed within deadline'), 'on_track' => __('Within deadline'), 'approaching' => __('Approaching'), 'missed' => __('Deadline missed'), 'overdue' => __('Overdue')][$deadline['state']] ?? '' }}</p>
</div>
@endforeach</section>
</div>
    <section id="next-documents" class="ui-card scroll-mt-20 p-5">
<h2 class="text-lg font-black mb-4">{{ __('Documents and versions') }}</h2>
@forelse($record->documents->sortByDesc('created_at') as $document)<div class="border-t py-4 flex flex-wrap items-center gap-3">
<div class="flex-1">
<strong>{{ $documentTypes[$document->document_type->value] ?? __('Document') }} — {{ __('Version') }} {{ \App\Support\Format\Ui::number($document->version) }}</strong> @if($document->drafted_by === \App\Enums\DraftedBy::Ai)<span class="rounded-full bg-primary-light text-primary px-2 py-1 text-xs font-bold">AI</span>@endif <p class="text-sm text-gray-500">{{ $documentStatuses[$document->status->value] ?? __('Document') }} · {{ ! $document->is_locked ? __('PDF after issuance') : ($document->pdf_status === 'ready' ? __('PDF ready') : ($document->pdf_status === 'failed' ? __('Could not prepare PDF') : __('PDF preparing'))) }}</p>
</div>
@if($document->pdf_status === 'ready')<x-ui.button href="{{ route('documents.pdf', $document) }}" variant="secondary">{{ __('Download PDF') }}</x-ui.button>@endif @if($canManage && $document->is_locked)<input wire:model="correctionReason" placeholder="{{ __('Correction reason') }}" class="ui-control rounded-xl border p-2">
<x-ui.button variant="secondary" wire:click="startCorrection({{ $document->id }})">{{ __('Start correction') }}</x-ui.button>
@endif</div>
@empty<p class="text-gray-500">{{ __('No documents.') }}</p>
@endforelse @error('correctionReason')<p class="text-red">{{ $message }}</p>
@enderror</section>
    <section class="ui-card p-5">
<h2 class="text-lg font-black mb-4">{{ __('Attachments') }}</h2>
@forelse($attachments as $attachment)<div class="border-t py-3 flex justify-between gap-3">
<span>{{ $attachment->original_name }} — {{ $attachment->kind->value }}</span>
<a href="{{ route('attachments.download', $attachment) }}" class="ui-link">{{ __('Download') }}</a>
</div>
@empty<p class="text-gray-500">{{ __('No attachments.') }}</p>
@endforelse</section>
    <section class="ui-card p-5">
        <h2 class="text-lg font-black mb-4">{{ __('Event timeline') }}</h2>
        <ol class="space-y-4">
            @forelse($record->events->sortByDesc('id') as $event)
                @php
                    $payload = $event->payload ?? [];
                    $signature = $signatures->get($payload['signature_id'] ?? 0);
                    $context = [
                        'assignee_name' => $assigneeNames[$payload['user_id'] ?? 0] ?? null,
                        'witnesses' => $signature ? [$signature->witness_1_name, $signature->witness_2_name] : [],
                        'minutes_typed_by' => !empty($payload['minutes_typed_by_pool_member_id']) ? \App\Models\CommitteePoolMember::query()->find($payload['minutes_typed_by_pool_member_id'])?->person_name : null,
                    ];
                @endphp
                <li class="relative border-s-2 border-primary ps-4">
                    <span class="ui-timeline-dot" aria-hidden="true"></span>
                    <strong>{{ \App\Support\Format\CaseTimeline::format($event->event_type, $payload, $context) }}</strong>
                    <p class="text-xs text-gray-500">{{ \App\Support\Format\Ui::date($event->created_at) }} · {{ $event->actorUser?->name }}</p>
                    @if($signature?->witnessScanAttachment)<a href="{{ route('attachments.download', $signature->witnessScanAttachment) }}" class="ui-link text-sm">{{ __('Download paper signed by both witnesses') }}</a>@endif
                    @if($event->notes)<p class="text-sm">{{ $event->notes }}</p>
@endif
                </li>
            @empty
                <li class="text-gray-500">{{ __('No events.') }}</li>
            @endforelse
        </ol>
    </section>
</div>
