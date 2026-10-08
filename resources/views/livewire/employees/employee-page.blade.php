<div class="space-y-6">
    <a href="{{ route('employees.index') }}" class="text-primary text-sm font-bold">← الموظفون</a>
    <header class="bg-white rounded-3xl p-6 shadow-sm">
        <h1 class="text-2xl font-black">{{ $employee->name }}</h1>
        <p class="text-gray-500 mt-2">{{ \App\Support\Format\ArabicDigits::convert($employee->employee_code) }} · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="text-sm text-gray-500 mt-2">تاريخ التعيين: {{ $employee->hire_date ? \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format($employee->hire_date, 'ar')) : '—' }}</p>
        @if($open)<p class="mt-4 rounded-xl bg-primary-light p-3">الحالة المفتوحة: <a class="text-primary font-bold" href="{{ route('cases.show', $open) }}">{{ \App\Support\Format\ArabicDigits::convert($open->case_number) }}</a> · {{ \App\Support\Format\StageIndicator::label($open->status) }}</p>@endif
    </header>
    <div class="grid grid-cols-3 gap-3">
        @foreach(['total' => 'إجمالي الحالات', 'warnings' => 'الإنذارات', 'last_year' => 'آخر ١٢ شهراً'] as $key => $label)
            <div class="bg-white rounded-2xl p-4"><p class="text-gray-500 text-sm">{{ $label }}</p><strong class="text-2xl">{{ \App\Support\Format\ArabicDigits::convert($counts[$key]) }}</strong></div>
        @endforeach
    </div>
    @if($partial)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">قد توجد حالات أخرى لا تظهر لك</p>@endif
    @foreach($sections as $section)
        @if($section === 'disciplinary_history')
            <section class="space-y-3"><h2 class="text-xl font-black">السجل الانضباطي</h2>
                @forelse($history as $case)
                    <article class="bg-white rounded-2xl p-4">
                        <a class="text-primary font-bold" href="{{ route('cases.show', $case) }}">الحالة {{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</a>
                        <span class="text-gray-500">· {{ $case->violation_type }} · {{ \App\Support\Format\OutcomeLabel::arabic($case->outcome) }} · {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($case->violation_time), 'ar')) }}</span>
                        @foreach($case->documents as $document)<a class="block text-sm text-primary mt-1" href="{{ route('documents.pdf', $document) }}">{{ $document->document_type->value }} · PDF</a>@endforeach
                    </article>
                @empty<p class="bg-white rounded-xl p-5 text-gray-500">لا توجد حالات سابقة ظاهرة.</p>@endforelse
            </section>
        @endif
    @endforeach
</div>
