<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">← الحالة</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">جلسة اللجنة</h1>
        @if($case->confirmedResponseText())<section class="bg-gray-50 rounded-2xl p-4"><h2 class="font-bold">نص منقول — الأصل هو الصورة الممسوحة</h2><p class="whitespace-pre-wrap">{{ $case->confirmedResponseText() }}</p></section>@endif
        <p class="text-gray-500">اختر الحاضرين وصوت كل عضو. يلزم ثلاثة أعضاء على الأقل، بينهم ممثل نقابة العمال. عند عدم بلوغ أغلبية يلزم إعادة التصويت.</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }} @if($case->session_majority_reached && $case->status === \App\Enums\CaseStatus::CommitteeSessionHeld)<a href="/cases/{{ $case->id }}" class="underline">كتابة التوصيات</a>@endif</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        <div class="space-y-2">
            @foreach($members as $member)
                <div class="flex flex-wrap items-center gap-4 rounded-2xl bg-gray-50 p-3" wire:key="member-{{ $member->id }}">
                    <label class="flex-1 flex gap-2 items-center"><input type="checkbox" wire:model.live="selected.{{ $member->id }}" aria-label="{{ $member->person_name }}"><span>{{ $member->person_name }}</span><small class="text-gray-500">{{ ['legal'=>'القانونية','hr'=>'الموارد البشرية','labor_union'=>'نقابة العمال'][$member->role_type->value] }}</small></label>
                    @if($selected[$member->id] ?? false)<select wire:model="votes.{{ $member->id }}" class="rounded-xl border p-2"><option value="">الصوت</option><option value="approve">موافق</option><option value="reject">رافض</option><option value="abstain">ممتنع</option></select>@endif
                </div>
            @endforeach
        </div>
        <label class="block font-bold">رئيس الجلسة <select wire:model="chairId" class="block w-full rounded-xl border p-3 mt-2"><option value="">اختر الرئيس</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</select></label>
        <label class="block font-bold">كتبها <select wire:model="minutesTypedBy" class="block w-full rounded-xl border p-3 mt-2"><option value="">من كتب المحضر؟</option>@foreach($members as $member)@if($selected[$member->id] ?? false)<option value="{{ $member->id }}">{{ $member->person_name }}</option>@endif @endforeach</select></label>
        <label class="block font-bold">محضر الجلسة <textarea wire:model="minutes" rows="8" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="font-bold">النتيجة <select wire:model="outcome" class="block w-full rounded-xl border p-3 mt-2"><option value="recommendation_only">توصية دون عقوبة</option><option value="warning">إنذار</option><option value="termination">إنهاء خدمة</option></select></label>
            <label class="font-bold">الأخذ بدفاع الموظف <select wire:model="defenseAcceptance" class="block w-full rounded-xl border p-3 mt-2"><option value="accepted_fully">كلياً</option><option value="accepted_partially">جزئياً</option><option value="rejected">لم يؤخذ به</option></select></label>
        </div>
        @foreach(['selected','chairId','minutesTypedBy','minutes'] as $field)@error($field)<p class="text-red text-sm">{{ $message }}</p>@enderror@endforeach
        <button wire:click="save" class="bg-primary text-white font-bold rounded-2xl px-6 py-3">حفظ الجلسة</button>
    </section>
</div>
