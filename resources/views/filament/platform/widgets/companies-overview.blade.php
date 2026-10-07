<x-filament-widgets::widget>
    <x-filament::section heading="نظرة عامة على الشركات">
        @php($companies = $this->companies())
        <div class="grid gap-3 sm:grid-cols-5 mb-5 text-sm">
            <div>الشركات: {{ \App\Support\Format\ArabicDigits::convert(count($companies)) }}</div>
            <div>الشركات النشطة: {{ \App\Support\Format\ArabicDigits::convert(count(array_filter($companies, fn ($row) => $row['active']))) }}</div>
            <div>الحالات المفتوحة: {{ \App\Support\Format\ArabicDigits::convert(array_sum(array_column($companies, 'open'))) }}</div>
            <div>تجاوزت المهلة: {{ \App\Support\Format\ArabicDigits::convert(array_sum(array_column($companies, 'past_due'))) }}</div>
            <div>الاستشارات المعلقة: {{ \App\Support\Format\ArabicDigits::convert(array_sum(array_column($companies, 'consultations'))) }}</div>
        </div>
        <div class="overflow-x-auto"><table class="w-full text-sm text-right"><thead><tr class="border-b"><th class="p-2">الشركة</th><th class="p-2">الحساب</th><th class="p-2">الاشتراك</th><th class="p-2">المفتوحة</th><th class="p-2">تجاوزت المهلة</th><th class="p-2">استشارات معلقة</th><th class="p-2">آخر نشاط</th></tr></thead><tbody>
            @forelse($companies as $row)<tr class="border-b"><td class="p-2"><a class="text-primary-600" href="{{ $row['url'] }}">{{ $row['name'] }}</a></td><td class="p-2">{{ $row['active'] ? 'نشط' : 'معطل' }}</td><td class="p-2">{{ match($row['subscription']) {'trial' => 'تجريبي', 'active' => 'نشط', 'suspended' => 'معلق', default => 'ملغى'} }}</td><td class="p-2">{{ \App\Support\Format\ArabicDigits::convert($row['open']) }}</td><td class="p-2">{{ \App\Support\Format\ArabicDigits::convert($row['past_due']) }}</td><td class="p-2">{{ \App\Support\Format\ArabicDigits::convert($row['consultations']) }}</td><td class="p-2">{{ $row['last_activity'] ? \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($row['last_activity'])->setTimezone('Asia/Baghdad'), 'ar')) : '—' }}</td></tr>
            @empty<tr><td colspan="7" class="p-5 text-center">لا توجد شركات.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-widgets::widget>
