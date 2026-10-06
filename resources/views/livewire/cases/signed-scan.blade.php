<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">← الحالة</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">مسح التوصيات الموقعة</h1>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        @if($case->status === \App\Enums\CaseStatus::AwaitingCommitteeSignatures)
            <p class="text-gray-500">سجل ما يظهر في خانة كل توقيع على المسح الورقي.</p>
            @foreach($attendees as $attendee)
                <label class="block border-t pt-3 font-bold">{{ $attendee->committeePoolMember->person_name }}
                    @if($checks->get($attendee->committee_pool_member_id)?->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty)<span class="text-red">— خانة التوقيع تبدو فارغة</span>@endif
                    <select wire:model="presence.{{ $attendee->id }}" class="block w-full rounded-xl border p-3 mt-2"><option value="">اختر</option><option value="filled">خانة التوقيع تبدو موقعة</option><option value="empty">خانة التوقيع تبدو فارغة</option></select>
                </label>
            @endforeach
            <input type="file" wire:model="scan" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-xl border p-3">
            @error('scan')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="upload" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">رفع المسح وتسجيل الخانات</button>
            @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin && $checks->contains(fn ($check) => $check->presence_check === \App\Enums\PresenceCheck::BoxAppearsEmpty))
                <div class="border-t pt-4"><label class="block font-bold">سبب تجاوز الخانة الفارغة <textarea wire:model="reason" class="block w-full rounded-xl border p-3 mt-2"></textarea></label><button wire:click="override" class="border border-amber text-amber rounded-2xl px-6 py-3 mt-3">تسجيل التجاوز</button></div>
            @endif
        @endif
        @if($case->status === \App\Enums\CaseStatus::SignedRecommendationsUploaded && $case->outcome === \App\Enums\Outcome::RecommendationOnly)
            @if($case->legal_review_requested_at && !$case->legal_review_completed_at && !$case->legal_review_cancelled_at)
                <p class="bg-amber-light text-amber rounded-2xl p-4">بانتظار الاستشارة القانونية قبل الأرشفة.</p>
                @if(auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)<label class="block font-bold">سبب إلغاء الاستشارة <textarea wire:model="reason" class="block w-full rounded-xl border p-3 mt-2"></textarea></label><button wire:click="cancelConsultation" class="border border-primary text-primary rounded-2xl px-6 py-3">إلغاء الاستشارة</button>@endif
            @else<button wire:click="close" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">إغلاق دون عقوبة</button>@endif
        @endif
        @error('reason')<p class="text-red">{{ $message }}</p>@enderror
    </section>
</div>
