<div><h1 class="text-2xl font-black mb-6">صندوق البلاغات</h1><div class="space-y-4">
@forelse($items as $item)
@if($item['type'] === 'email')
@php($email = $item['model'])
<section wire:key="email-{{ $email->id }}" class="bg-white rounded-2xl p-5 shadow-sm">
    <div class="flex flex-wrap justify-between gap-2"><div><span class="inline-block rounded-full bg-primary-light text-primary text-xs font-bold px-3 py-1">بريد مُعاد توجيهه</span><h2 class="font-bold mt-2">{{ $email->subject }}</h2></div><span class="text-sm text-gray-500">{{ $email->sender?->name }} · {{ $email->sender?->email }} · {{ $email->received_at->format('Y-m-d H:i') }}</span></div>
    <p class="text-sm text-gray-600 my-3 whitespace-pre-line">{{ $email->body_text }}</p>
    @if($email->body_truncated)<p class="text-xs text-gray-500">تم اختصار نص البريد.</p>@endif
    @foreach($email->attachments as $attachment)<a class="text-sm text-primary underline ml-3" href="/attachments/{{ $attachment->id }}">{{ $attachment->original_name }}</a>@endforeach
    @foreach($email->excluded_attachments ?? [] as $name)<p class="text-xs text-gray-500">تم استبعاد مرفق: {{ $name }}</p>@endforeach
    <div class="flex flex-wrap gap-3 items-end mt-4">
        <button wire:click="convertEmail({{ $email->id }})" class="bg-primary text-white px-4 py-2 rounded-2xl shadow-lg shadow-indigo-200 text-sm font-bold">إنشاء حالة</button>
        <label class="text-xs">ربط بحالة موجودة<select wire:model="emailCaseIds.{{ $email->id }}" class="block border border-gray-200 rounded-xl p-2 mt-1"><option value="">حالة جديدة</option>@foreach(\App\Support\Access\CaseVisibility::for(auth()->user())->latest()->get() as $case)<option value="{{ $case->id }}">{{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</option>@endforeach</select></label>
        <button wire:click="linkEmail({{ $email->id }})" class="text-primary text-sm font-bold px-3 py-2 rounded-xl border border-primary">ربط بالحالة</button>
        <label class="text-xs">سبب الاستبعاد<input wire:model="emailReasons.{{ $email->id }}" class="block border border-gray-200 rounded-xl p-2 mt-1"></label>
        <button wire:click="dismissEmail({{ $email->id }})" class="text-red text-sm font-bold px-3 py-2 rounded-xl border border-red-light">استبعاد</button>
    </div>
    @error('emailReasons.'.$email->id)<p class="text-red text-sm mt-2">{{ $message }}</p>@enderror
</section>
@else
@php($report = $item['model'])
<section wire:key="report-{{ $report->id }}" class="bg-white rounded-2xl p-5 shadow-sm">
    <div class="flex justify-between"><h2 class="font-bold">{{ $report->violation_type }}</h2><span class="text-sm text-gray-500">{{ $report->reporter?->name }}</span></div>
    <p class="text-sm text-gray-600 my-3">{{ $report->description }}</p>
    @foreach($report->attachments as $attachment)<a class="text-sm text-primary underline ml-3" href="/attachments/{{ $attachment->id }}">{{ $attachment->original_name }}</a>@endforeach
    <div class="flex flex-wrap gap-3 items-end">
        <button wire:click="convert({{ $report->id }})" class="bg-primary text-white px-4 py-2 rounded-2xl shadow-lg shadow-indigo-200 text-sm font-bold">تحويل إلى حالة</button>
        <label class="text-xs">ربط بحالة مكررة<select wire:model="existingCaseIds.{{ $report->id }}" class="block border border-gray-200 rounded-xl p-2 mt-1"><option value="">حالة جديدة</option>@foreach($insights->duplicateCandidates($report)['cases'] as $case)@if(\App\Support\Access\CaseVisibility::for(auth()->user())->whereKey($case->id)->exists())<option value="{{ $case->id }}">{{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</option>@endif@endforeach</select></label>
        <label class="text-xs">سبب الاستبعاد<input wire:model="reasons.{{ $report->id }}" class="block border border-gray-200 rounded-xl p-2 mt-1"></label>
        <button wire:click="dismiss({{ $report->id }})" class="text-red text-sm font-bold px-3 py-2 rounded-xl border border-red-light">استبعاد</button>
    </div>
    @error('reasons.'.$report->id)<p class="text-red text-sm mt-2">{{ $message }}</p>@enderror
</section>
@endif
@empty<p class="bg-white rounded-2xl p-8 text-center text-gray-500">لا توجد بلاغات جديدة.</p>@endforelse
</div></div>
