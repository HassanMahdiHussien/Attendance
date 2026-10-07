<div class="max-w-6xl mx-auto space-y-5">
    <a href="/my-cases" class="text-primary text-sm">← حالاتي</a>
    <section class="bg-white rounded-3xl p-6 space-y-5">
        <h1 class="text-2xl font-black">الاستشارة القانونية — الحالة {{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</h1>
        <p>{{ $case->employee?->name }} — {{ $case->violation_type }}</p>
        <h2 class="font-bold">محضر جلسة اللجنة</h2><p class="whitespace-pre-wrap">{{ $case->committee_session_notes }}</p>
        @foreach($case->documents as $document)
            <section class="border-t pt-4"><h2 class="font-bold">{{ ['defense_letter'=>'كتاب طلب الدفاع','recommendations'=>'توصيات اللجنة','decision_letter'=>'كتاب القرار'][$document->document_type->value] }}</h2>@if($document->pdf_status === 'ready')<a href="{{ route('documents.pdf', $document) }}" class="text-primary underline">تنزيل المستند الصادر</a>@endif
                @foreach($document->contents as $content)<div dir="{{ $content->language_code === 'en' ? 'ltr' : 'rtl' }}" class="my-3"><strong>{{ ['ar'=>'العربية','en'=>'English','ku'=>'کوردی'][$content->language_code] ?? $content->language_code }}</strong><div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->body) !!}</div>@if($content->secondary_body)<div class="body">{!! app(\App\Documents\BodyFormatter::class)->format($content->secondary_body) !!}</div>@endif</div>@endforeach
            </section>
        @endforeach
        <h2 class="font-bold">المرفقات والمسوح</h2>
        @foreach($attachments as $attachment)<a href="{{ route('attachments.download', $attachment) }}" class="block text-primary underline">{{ $attachment->original_name }}</a>@endforeach
        @if($notice)<p role="status">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="text-red">{{ $message }}</p>@enderror
        @if(!$case->legal_review_completed_at && $case->status !== \App\Enums\CaseStatus::ArchivedFinal)
            <label class="block font-bold">ملاحظة الاستشارة <textarea wire:model="note" rows="7" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            @error('note')<p class="text-red">{{ $message }}</p>@enderror
            <button wire:click="complete" class="bg-primary text-white rounded-2xl px-5 py-3">إكمال الاستشارة</button>
        @else<p class="text-gray-500">اكتملت الاستشارة. {{ $case->legal_review_note }}</p>@endif
    </section>
</div>
