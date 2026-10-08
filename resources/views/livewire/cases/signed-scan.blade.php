<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">{{ __('Signed recommendations scan') }}</h1>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingCommitteeSignatures)
            @if($case->ai_request_document_type === 'signature_presence')<div wire:poll.3s><p role="status">{{ __('Reading the scan…') }}</p></div>@endif
            @if($case->events()->where('event_type', 'signature_presence_failed')->exists())<p role="alert">{{ __('AI reading failed — you can record the boxes manually') }}</p>@endif
            @if($latestScan)<object data="{{ route('attachments.download', $latestScan) }}?preview=1" type="{{ $latestScan->mime_type }}" class="w-full h-96 border rounded-xl"><a href="{{ route('attachments.download', $latestScan) }}" class="text-primary underline">{{ __('Open scan for review') }}</a></object>@endif
            <p class="text-gray-500">{{ __('Record what appears in each signature box on the paper scan.') }}</p>
            @error('notice')<p role="alert" class="text-red font-bold">{{ $message }}</p>@enderror
            @foreach($attendees as $attendee)
                <label class="block border-t pt-3 font-bold {{ $errors->has('notice') && !in_array($presence[$attendee->id] ?? null, ['filled', 'empty'], true) ? 'border-red bg-red-light rounded-xl px-3 pb-3' : '' }}">{{ $attendee->committeePoolMember->person_name }}
                    @if($suggestion && isset($suggestion->results[$attendee->id]))<small class="block text-gray-500">{{ __('AI suggestion') }}: {{ \App\Services\Cases\PresenceWording::suggestion($suggestion->results[$attendee->id]['result']) }} — {{ $suggestion->results[$attendee->id]['reason'] }}</small>@endif
                    @if($checks->get($attendee->committee_pool_member_id)?->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty)<span class="text-red">— {{ \App\Services\Cases\PresenceWording::choice('empty') }}</span>@endif
                    @if($checks->get($attendee->committee_pool_member_id)?->aiSuggestionDisposition())<small class="block text-gray-500">{{ $checks->get($attendee->committee_pool_member_id)->aiSuggestionDisposition() === 'accepted' ? __('HR record agreed with the suggestion') : __('HR changed the suggestion') }}</small>@endif
                    <select wire:model="presence.{{ $attendee->id }}" class="block w-full rounded-xl border p-3 mt-2 {{ $errors->has('notice') && !in_array($presence[$attendee->id] ?? null, ['filled', 'empty'], true) ? 'border-red' : '' }}"><option value="">{{ __('Select') }}</option><option value="filled">{{ \App\Services\Cases\PresenceWording::choice('filled') }}</option><option value="empty">{{ \App\Services\Cases\PresenceWording::choice('empty') }}</option></select>
                </label>
            @endforeach
            <input type="file" wire:model="scan" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-xl border p-3">
            @error('scan')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="upload" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">{{ __('Upload scan and record boxes') }}</button>
            <button wire:click="uploadForReading" class="border border-primary text-primary rounded-2xl px-6 py-3 font-bold">{{ __('Upload and read with AI') }}</button>
            @if($latestScan)<button wire:click="recordReadPresence" class="border border-primary text-primary rounded-2xl px-6 py-3 font-bold">{{ __('Record boxes after reviewing scan') }}</button>@endif
            @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin && $checks->contains(fn ($check) => $check->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty))
                <div class="border-t pt-4"><label class="block font-bold">{{ __('Reason for overriding empty box') }} <textarea wire:model="reason" class="block w-full rounded-xl border p-3 mt-2"></textarea></label><button wire:click="override" class="border border-amber text-amber rounded-2xl px-6 py-3 mt-3">{{ __('Record override') }}</button></div>
            @endif
        @endif
        @if($case->status === \App\Enums\CaseStatus::SignedRecommendationsUploaded && $case->outcome === \App\Enums\Outcome::RecommendationOnly)
            @if($case->legal_review_requested_at && !$case->legal_review_completed_at && !$case->legal_review_cancelled_at)
                <p class="bg-amber-light text-amber rounded-2xl p-4">{{ __('Awaiting legal consultation before archiving.') }}</p>
                @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)<label class="block font-bold">{{ __('Reason for cancelling consultation') }} <textarea wire:model="reason" class="block w-full rounded-xl border p-3 mt-2"></textarea></label><button wire:click="cancelConsultation" class="border border-primary text-primary rounded-2xl px-6 py-3">{{ __('Cancel consultation') }}</button>@endif
            @else<button wire:click="close" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">{{ __('Close without penalty') }}</button>@endif
        @endif
        @error('reason')<p class="text-red">{{ $message }}</p>@enderror
    </section>
</div>
