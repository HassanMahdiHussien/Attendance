<div class="space-y-7">
    <div><h1 class="text-2xl font-black">لوحة المتابعة</h1><p class="text-sm text-gray-400 mt-1">الحالات والمواعيد والأنماط في شركتك</p></div>
    <div class="bg-white rounded-2xl p-4 flex flex-wrap gap-3 shadow-sm">
        <select wire:model.live="branch" aria-label="الفرع" class="rounded-xl border border-gray-200 p-3"><option value="">كل الفروع</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <select wire:model.live="period" aria-label="الفترة" class="rounded-xl border border-gray-200 p-3"><option value="3">٣ أشهر</option><option value="6">٦ أشهر</option><option value="12">١٢ شهراً</option><option value="custom">فترة مخصصة</option></select>
        @if($period === 'custom')<input type="date" wire:model.live="from" aria-label="من" class="rounded-xl border border-gray-200 p-3"><input type="date" wire:model.live="to" aria-label="إلى" class="rounded-xl border border-gray-200 p-3">@endif
    </div>
    @php($filterQuery = ['branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to])
    <section><h2 class="text-lg font-black mb-3">تنبيهات المواعيد</h2><div class="space-y-2">
        @forelse($alerts as $alert)
            @php($label = match($alert['type']) {'procedure_approaching' => 'مهلة الإجراء تقترب', 'procedure_overdue' => 'مهلة الإجراء فاتت', 'response_approaching' => 'مهلة الرد تقترب', 'response_missed' => 'مهلة الرد فاتت', 'consultation_pending' => 'استشارة قانونية معلقة', default => 'نافذة الطعن تنتهي قريباً'})
            <a href="/cases/{{ $alert['case']->id }}" class="block bg-red-light text-red rounded-2xl p-4 font-bold">{{ $label }} · <span class="font-mono">{{ \App\Support\Format\ArabicDigits::convert($alert['case']->case_number) }}</span> · {{ $alert['case']->employee?->name }} @if($alert['due']) · {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format($alert['due'], 'ar')) }} · {{ $alert['remaining_hours'] >= 0 ? 'باقي' : 'متأخرة' }} {{ abs($alert['remaining_hours']) > 48 ? \App\Support\Format\ArabicDigits::convert(intdiv(abs($alert['remaining_hours']), 24)).' يوم' : \App\Support\Format\ArabicDigits::convert(abs($alert['remaining_hours'])).' ساعة' }} @endif @if($alert['waiting_days'] !== null) · منذ {{ \App\Support\Format\ArabicDigits::convert($alert['waiting_days']) }} يوم @endif</a>
        @empty<p class="bg-white rounded-2xl p-6 text-gray-500">لا توجد تنبيهات حالياً.</p>@endforelse
    </div></section>
    <section><h2 class="text-lg font-black mb-3">المؤشرات</h2><div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach(['open' => 'الحالات المفتوحة', 'closed_month' => 'المغلقة هذا الشهر', 'recommendation_only' => 'توصية فقط', 'warning' => 'إنذار', 'termination' => 'إنهاء خدمة', 'within15_numerator' => 'ضمن ١٥ يوماً', 'within15_denominator' => 'حالات قابلة للقياس'] as $key => $label)
            <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => $key]) }}" class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">{{ $label }}</span><strong class="block text-2xl text-primary mt-2">{{ \App\Support\Format\ArabicDigits::convert($counts[$key]) }}</strong></a>
        @endforeach
        <div class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">متوسط أيام الإجراء</span><strong class="block text-2xl text-primary mt-2">{{ $counts['average_days'] === null ? '—' : \App\Support\Format\ArabicDigits::convert((string) $counts['average_days']) }}</strong></div>
        <div class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">النسبة ضمن ١٥ يوماً</span><strong class="block text-2xl text-primary mt-2">{{ $counts['within15_percent'] === null ? '—' : \App\Support\Format\ArabicDigits::convert($counts['within15_percent']).'٪' }}</strong></div>
    </div></section>
    <section><h2 class="text-lg font-black mb-3">تنبيهات التكرار</h2><div class="space-y-2">@forelse($thresholds as $row)
        <a class="block bg-amber-light text-amber rounded-2xl p-4 font-bold" href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'threshold', 'group' => $row['group'], 'key' => $row['key'], 'violation' => $row['violation']]) }}">{{ $row['violation'] }} · {{ $row['label'] }} · {{ \App\Support\Format\ArabicDigits::convert($row['count']) }}</a>
        @empty<p class="bg-white rounded-2xl p-6 text-gray-500">لا توجد أنماط تجاوزت الحد المحدد.</p>@endforelse</div></section>
    <section><h2 class="text-lg font-black mb-3">مناطق التكرار</h2><div class="grid md:grid-cols-2 gap-4">
        @foreach(['branch' => 'الفروع', 'department' => 'القسم الحالي', 'location' => 'المواقع', 'violation' => 'نوع المخالفة', 'responsible' => 'الشخص المنسوب إليه', 'employee' => 'الموظفون'] as $group => $title)
            <div class="bg-white rounded-2xl p-5 shadow-sm"><h3 class="font-bold mb-4">{{ $title }}</h3><div class="space-y-3">@forelse($hotspots[$group] as $row)
                <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'hotspot', 'group' => $group, 'key' => $row['key']]) }}" class="block text-sm"><span class="flex justify-between"><span>{{ $row['label'] }}</span><strong>{{ \App\Support\Format\ArabicDigits::convert($row['count']) }} <span class="text-gray-400">{{ $row['delta'] > 0 ? '↑' : ($row['delta'] < 0 ? '↓' : '→') }} {{ \App\Support\Format\ArabicDigits::convert(abs($row['delta'])) }}</span></strong></span><span class="block bg-primary-light rounded-full h-1.5 mt-2"><span class="block bg-primary rounded-full h-1.5" style="width: {{ $row['width'] }}%"></span></span></a>
            @empty<p class="text-gray-400">لا توجد حالات في هذه الفترة.</p>@endforelse</div></div>
        @endforeach
    </div></section>
</div>
