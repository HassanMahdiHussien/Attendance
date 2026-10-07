<div>
    <h1 class="text-2xl font-black mb-6">حالاتي</h1>
    @if($summary)
        <div class="grid sm:grid-cols-3 gap-3 mb-6">
            <div class="bg-white rounded-2xl p-5 shadow-sm">استشارات بانتظار المراجعة<strong class="block text-2xl text-primary">{{ \App\Support\Format\ArabicDigits::convert($summary['waiting']) }}</strong></div>
            <div class="bg-white rounded-2xl p-5 shadow-sm">استشارات مكتملة هذا الشهر<strong class="block text-2xl text-primary">{{ \App\Support\Format\ArabicDigits::convert($summary['completed_month']) }}</strong></div>
            <div class="bg-white rounded-2xl p-5 shadow-sm">أقدم استشارة معلقة
                @if($summary['oldest'])
                    <span class="block text-sm">موعد الإجراء: {{ $summary['oldest']['due'] ? \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format($summary['oldest']['due'], 'ar')) : 'غير محدد' }}</span>
                    @if($summary['oldest']['url'])<a class="text-primary text-sm font-bold" href="{{ $summary['oldest']['url'] }}">عرض الحالة</a>@endif
                @else<span class="block text-sm text-gray-400">لا توجد استشارة معلقة.</span>@endif
            </div>
        </div>
    @endif
    <div class="space-y-3">@forelse($cases as $case)<a href="/cases/{{ $case->id }}" class="block bg-white rounded-2xl p-5 shadow-sm"><span class="font-bold">{{ $case->employee?->name }}</span><span class="text-xs font-mono text-gray-400 mr-2">{{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</span></a>@empty<p class="bg-white rounded-2xl p-8 text-center text-gray-500">لا توجد حالات متاحة لك حالياً.</p>@endforelse</div>
</div>
