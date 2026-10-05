<div class="max-w-5xl mx-auto space-y-6">
    <a href="/cases/{{ $record->id }}" class="text-primary text-sm">← الحالة</a>
    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm">
        <div class="flex items-center gap-2 text-amber font-bold mb-5"><span class="w-2 h-2 rounded-full bg-amber"></span>{{ $document->is_locked ? 'صدر المستند' : 'مسودة — بانتظار تأكيدك' }}</div>
        <h1 class="text-2xl font-black mb-5">تأكيد كتاب طلب الدفاع</h1>
        @if($notice)<p role="status" class="bg-primary-light text-primary rounded-2xl p-4 mb-5">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4 mb-5">{{ $message }}</p>@enderror
        <div class="bg-gray-50 rounded-2xl p-4 md:p-6 mb-5" style="font-family: Amiri, serif">
            @if($document->is_locked)
                <p class="mb-3">العدد: {{ $document->reference_number }}</p>
                @if($document->pdf_status === 'ready')<a href="{{ route('documents.pdf', $document) }}" class="text-primary font-bold">تنزيل PDF</a>
                @elseif($document->pdf_status === 'failed')<p class="text-red">تعذر إعداد PDF.</p>
                @else<p class="text-amber">ملف PDF قيد الإعداد.</p>@endif
            @else
                <iframe title="معاينة كتاب طلب الدفاع" sandbox="" src="{{ route('documents.preview', $document) }}" class="w-full h-[650px] bg-white rounded-xl border border-gray-200"></iframe>
            @endif
        </div>
        @unless($document->is_locked)
            <label for="reference" class="block font-bold mb-2">العدد</label>
            <input id="reference" wire:model.live="reference" dir="ltr" class="w-full md:w-64 rounded-2xl border border-gray-200 p-3 font-mono mb-4">
            @error('reference')<p class="text-red text-sm">{{ $message }}</p>@enderror
            @if($record->company->requires_second_review)
                <div class="bg-primary-light rounded-2xl p-4 mb-5 text-sm">
                    @if($document->second_reviewed_by)راجع هذا المستند مستخدم آخر.
                    @elseتتطلب الشركة مراجعة مستخدم آخر قبل الإصدار. <button wire:click="markReviewed" class="text-primary font-bold underline">تسجيل المراجعة الثانية</button>@endif
                </div>
            @endif
            <div class="bg-amber-light text-amber rounded-2xl p-4 mb-5">⚠ تأكد من صحة الأسماء والتواريخ — لا يمكن التعديل بعد الإصدار</div>
            <div class="flex flex-wrap gap-3">
                <button wire:click="issue" class="bg-primary text-white font-bold rounded-2xl px-6 py-3 shadow-lg shadow-indigo-200">✓ تأكيد وإصدار</button>
                <button wire:click="sendBack" class="border border-gray-200 rounded-2xl px-6 py-3">تعديل</button>
            </div>
        @endunless
    </div>
</div>
