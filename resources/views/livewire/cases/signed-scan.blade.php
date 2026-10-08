<div class="mx-auto max-w-5xl space-y-5">
    <x-ui.page-header :title="__('Signed recommendations scan')" back="/cases/{{ $case->id }}" />
    <x-ui.card class="space-y-5">
        @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingCommitteeSignatures)
            @if($case->ai_request_document_type === 'signature_presence')<div wire:poll.3s><p role="status">{{ __('Reading the scan…') }}</p></div>@endif
            @if($case->events()->where('event_type', 'signature_presence_failed')->exists())<x-ui.alert tone="amber">{{ __('AI reading failed — you can record the boxes manually') }}</x-ui.alert>@endif
            @if($latestScan)<object data="{{ route('attachments.download', $latestScan) }}?preview=1" type="{{ $latestScan->mime_type }}" class="h-96 w-full rounded-xl border"><a href="{{ route('attachments.download', $latestScan) }}" class="ui-link">{{ __('Open scan for review') }}</a></object>@endif
            <p class="text-gray-500">{{ __('Record what appears in each signature box on the paper scan.') }}</p>
            @error('notice')<p role="alert" class="font-bold text-red-700">{{ $message }}</p>@enderror
            @foreach($attendees as $attendee)
                <label class="block border-t pt-3 font-bold {{ $errors->has('notice') && !in_array($presence[$attendee->id] ?? null, ['filled', 'empty'], true) ? 'border-red bg-red-light rounded-xl px-3 pb-3' : '' }}">
                    {{ $attendee->committeePoolMember->person_name }}
                    @if($suggestion && isset($suggestion->results[$attendee->id]))<small class="block text-gray-500">{{ __('AI suggestion') }}: {{ \App\Services\Cases\PresenceWording::suggestion($suggestion->results[$attendee->id]['result']) }} — {{ $suggestion->results[$attendee->id]['reason'] === 'messages.ai.signature_box_unclear' ? __('messages.ai.signature_box_unclear') : $suggestion->results[$attendee->id]['reason'] }}</small>@endif
                    @if($checks->get($attendee->committee_pool_member_id)?->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty)<x-ui.badge tone="red">{{ \App\Services\Cases\PresenceWording::choice('empty') }}</x-ui.badge>@endif
                    @if($checks->get($attendee->committee_pool_member_id)?->aiSuggestionDisposition())<small class="block text-gray-500">{{ $checks->get($attendee->committee_pool_member_id)->aiSuggestionDisposition() === 'accepted' ? __('HR record agreed with the suggestion') : __('HR changed the suggestion') }}</small>@endif
                    <x-ui.select wire:model="presence.{{ $attendee->id }}" class="mt-2 {{ $errors->has('notice') && !in_array($presence[$attendee->id] ?? null, ['filled', 'empty'], true) ? 'border-red' : '' }}"><option value="">{{ __('Select') }}</option><option value="filled">{{ \App\Services\Cases\PresenceWording::choice('filled') }}</option><option value="empty">{{ \App\Services\Cases\PresenceWording::choice('empty') }}</option></x-ui.select>
                </label>
            @endforeach
            <label class="block text-sm font-bold">{{ __('Signed recommendations scan') }}<input type="file" wire:model="scan" accept=".pdf,.jpg,.jpeg,.png" class="ui-control mt-2"></label>
            @error('scan')<p class="text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3"><x-ui.button wire:click="upload">{{ __('Upload scan and record boxes') }}</x-ui.button><x-ui.button variant="secondary" wire:click="uploadForReading">{{ __('Upload and read with AI') }}</x-ui.button>@if($latestScan)<x-ui.button variant="secondary" wire:click="recordReadPresence">{{ __('Record boxes after reviewing scan') }}</x-ui.button>@endif</div>
            @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin && $checks->contains(fn ($check) => $check->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty))
                <div class="border-t border-[#EEF0F4] pt-4"><label class="block font-bold">{{ __('Reason for overriding empty box') }}<x-ui.textarea wire:model="reason" class="mt-2" /></label><x-ui.button variant="secondary" wire:click="override" class="mt-3">{{ __('Record override') }}</x-ui.button></div>
            @endif
        @endif
        @if($case->status === \App\Enums\CaseStatus::SignedRecommendationsUploaded && $case->outcome === \App\Enums\Outcome::RecommendationOnly)
            @if($case->legal_review_requested_at && !$case->legal_review_completed_at && !$case->legal_review_cancelled_at)
                <x-ui.alert tone="amber">{{ __('Awaiting legal consultation before archiving.') }}</x-ui.alert>
                @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)<label class="block font-bold">{{ __('Reason for cancelling consultation') }}<x-ui.textarea wire:model="reason" class="mt-2" /></label><x-ui.button variant="secondary" wire:click="cancelConsultation">{{ __('Cancel consultation') }}</x-ui.button>@endif
            @else<x-ui.button wire:click="close">{{ __('Close without penalty') }}</x-ui.button>@endif
        @endif
        @error('reason')<p class="text-red-700">{{ $message }}</p>@enderror
    </x-ui.card>
</div>
