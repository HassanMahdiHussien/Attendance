<div class="bg-white rounded-3xl p-8 shadow-sm">
    <a href="{{ \App\Support\Access\Landing::for(auth()->user()) }}" class="text-primary text-sm">← العودة</a>
    <h1 class="text-2xl font-black mt-4">الحالة {{ \App\Support\Format\ArabicDigits::convert(str_pad($record->case_number, 4, '0', STR_PAD_LEFT)) }}</h1>
    <p class="text-gray-500 mt-2">{{ $record->employee?->name }} — {{ $record->violation_type }}</p>
    <p class="text-sm text-gray-400 mt-5">تفاصيل الحالة وإجراءاتها ستظهر هنا.</p>
</div>
