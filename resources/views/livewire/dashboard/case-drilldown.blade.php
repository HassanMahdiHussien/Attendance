<div>
    <a href="{{ auth()->user()->primary_role === \App\Enums\UserRole::HrPartnerStaff ? '/home' : '/dashboard' }}" class="text-primary text-sm font-bold">← لوحة المتابعة</a>
    <h1 class="text-2xl font-black my-6">الحالات المرتبطة بالمؤشر</h1>
    <p class="text-sm text-gray-500 mb-4">عدد الحالات: {{ \App\Support\Format\ArabicDigits::convert($cases->total()) }}</p>
    <div class="space-y-3">@forelse($cases as $case)
        @php
            $partner = auth()->user()->primary_role === \App\Enums\UserRole::HrPartnerStaff;
            $name = $partner ? $case->employee_name : $case->employee?->name;
            $position = $partner ? $case->employee_position : $case->employee?->position;
            $department = $partner ? $case->employee_department : $case->employee?->department;
            $archived = $case->status === \App\Enums\CaseStatus::ArchivedFinal;
            $step = \App\Support\Format\StageIndicator::step($case->status);
        @endphp
        <a href="/cases/{{ $case->id }}" class="bg-white rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md {{ $archived ? 'opacity-60' : '' }}">
            <span class="w-11 h-11 rounded-full bg-primary-light text-primary font-bold flex items-center justify-center shrink-0">{{ \App\Support\Format\Initials::for($name ?? '') }}</span>
            <span class="flex-1 min-w-0"><span class="font-bold">{{ $name }}</span><span class="font-mono text-xs text-gray-400 mr-2">{{ \App\Support\Format\ArabicDigits::convert($case->case_number) }}</span><span class="block text-xs text-gray-400 mt-1">{{ $position }} — {{ $department }} · {{ $case->violation_type }} · الواقعة {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($case->violation_time)->setTimezone('Asia/Baghdad'), 'ar')) }}</span>@if($case->defense_letter_issued_at)<span class="block text-xs text-gray-400 mt-1">كتاب الدفاع {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($case->defense_letter_issued_at)->setTimezone('Asia/Baghdad'), 'ar')) }}</span>@endif @if($case->decision_issued_at)<span class="block text-xs text-gray-400 mt-1">القرار {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($case->decision_issued_at)->setTimezone('Asia/Baghdad'), 'ar')) }}</span>@elseif($case->closed_at)<span class="block text-xs text-gray-400 mt-1">الإغلاق {{ \App\Support\Format\ArabicDigits::convert(\App\Documents\IraqiDate::format(\Carbon\CarbonImmutable::parse($case->closed_at)->setTimezone('Asia/Baghdad'), 'ar')) }}</span>@endif</span>
            <span class="hidden sm:flex gap-1.5" aria-label="{{ \App\Support\Format\StageIndicator::label($case->status) }}">@for($i=1;$i<=5;$i++)<span class="w-2 h-2 rounded-full {{ $i <= $step ? ($archived ? 'bg-green' : 'bg-primary') : 'bg-gray-200' }}"></span>@endfor</span>
            <span class="text-xs font-bold px-3 py-1.5 rounded-full {{ $archived ? 'bg-green-light text-green' : 'bg-primary-light text-primary' }}">{{ \App\Support\Format\StageIndicator::label($case->status) }}</span>
        </a>
        @if($case->employee_id && app(\App\Policies\EmployeeProfilePolicy::class)->companyAllowed(auth()->user(), (int) $case->company_id))<a href="{{ route('employees.show', $case->employee_id) }}" class="block text-sm text-primary mr-4">صفحة الموظف</a>@endif
    @empty<p class="bg-white rounded-2xl p-8 text-gray-500">لا توجد حالات مطابقة.</p>@endforelse</div>
    <div class="mt-6">{{ $cases->links() }}</div>
</div>
