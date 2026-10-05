<div>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div><h1 class="text-2xl font-black">لوحة الحالات</h1><p class="text-sm text-gray-400 mt-1">الحالات التي تحتاج إلى متابعتك</p></div>
        <a href="/cases/new" class="bg-primary text-white text-sm font-bold px-5 py-3 rounded-2xl shadow-lg shadow-indigo-200">+ فتح حالة جديدة</a>
    </div>
    @if($urgentCount > 0)
        <div class="bg-red-light rounded-2xl p-4 mb-6 flex items-center gap-3" role="alert">
            <span class="w-8 h-8 rounded-full bg-red text-white flex items-center justify-center text-sm">!</span>
            <div class="text-sm"><strong class="text-red">{{ \App\Support\Format\ArabicDigits::convert($urgentCount) }} {{ $urgentCount === 1 ? 'حالة قاربت' : 'حالات قاربت' }} مهلة الـ١٥ يوم القانونية</strong><span class="text-gray-500"> — يلزم اتخاذ قرار قريباً</span></div>
        </div>
    @endif
    <div class="flex flex-wrap gap-3 mb-6">
        <input wire:model.live.debounce.300ms="search" aria-label="بحث" placeholder="ابحث بالاسم أو رقم الحالة" class="rounded-xl border border-gray-200 p-3 flex-1 min-w-48">
        <select wire:model.live="branch" aria-label="الفرع" class="rounded-xl border border-gray-200 p-3"><option value="">كل الفروع</option>@foreach($branches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach</select>
        <select wire:model.live="stage" aria-label="المرحلة" class="rounded-xl border border-gray-200 p-3"><option value="">كل المراحل</option>@foreach($stages as $number)<option value="{{ $number }}">{{ \App\Support\Format\StageIndicator::stageLabel($number) }}</option>@endforeach</select>
    </div>
    <div class="grid grid-cols-1 gap-3">
        @forelse($cases as $case)
            @php
                $archived = $case->status === \App\Enums\CaseStatus::ArchivedFinal;
                $due = $deadlines->procedureDue($case);
                $days = $due ? (int) now('Asia/Baghdad')->startOfDay()->diffInDays($due->startOfDay(), false) : null;
                $urgent = !$archived && $case->decision_issued_at === null && $days !== null && $days <= 2;
                $step = \App\Support\Format\StageIndicator::step($case->status);
                $waiting = in_array($case->status, [\App\Enums\CaseStatus::AwaitingEmployeeResponse, \App\Enums\CaseStatus::AwaitingCommitteeSignatures], true);
                $pill = $archived ? 'مغلقة' : ($urgent ? ($days < 0 ? 'متأخرة' : \App\Support\Format\StageIndicator::remaining($days)) : ($waiting ? ($case->status === \App\Enums\CaseStatus::AwaitingEmployeeResponse ? 'بانتظار الرد' : 'بانتظار توقيع') : \App\Support\Format\StageIndicator::label($case->status)));
                $tone = $archived ? 'bg-green-light text-green' : ($urgent ? 'bg-red-light text-red' : ($waiting ? 'bg-amber-light text-amber' : 'bg-primary-light text-primary'));
            @endphp
            <a href="/cases/{{ $case->id }}" wire:key="case-{{ $case->id }}" class="bg-white rounded-2xl p-4 flex items-center gap-4 shadow-sm {{ $archived ? 'opacity-60' : '' }} hover:shadow-md">
                <div class="w-11 h-11 rounded-full {{ $archived ? 'bg-gray-100 text-gray-400' : 'bg-primary-light text-primary' }} font-bold flex items-center justify-center shrink-0">{{ \App\Support\Format\Initials::for($case->employee?->name ?? '') }}</div>
                <div class="flex-1 min-w-0"><div class="flex items-center gap-2"><span class="font-bold truncate">{{ $case->employee?->name }}</span><span class="text-xs font-mono text-gray-300">{{ \App\Support\Format\ArabicDigits::convert(str_pad($case->case_number, 4, '0', STR_PAD_LEFT)) }}</span></div><div class="text-xs text-gray-400 mt-0.5">{{ $case->employee?->position }} — {{ $case->employee?->department }}</div></div>
                <div class="hidden sm:flex items-center gap-1.5 shrink-0" aria-label="{{ \App\Support\Format\StageIndicator::label($case->status) }}">@for($i=1;$i<=5;$i++)<span class="w-2 h-2 rounded-full {{ $i <= $step ? ($archived ? 'bg-green' : 'bg-primary') : 'bg-gray-200' }}"></span>@endfor</div>
                <span class="sm:hidden text-xs text-gray-500">{{ \App\Support\Format\StageIndicator::label($case->status) }}</span>
                <span class="text-xs font-bold px-3 py-1.5 rounded-full shrink-0 {{ $tone }}">{{ $pill }}</span>
            </a>
        @empty
            <p class="bg-white rounded-2xl p-8 text-center text-gray-500">لا توجد حالات مطابقة.</p>
        @endforelse
    </div>
    <div class="mt-6 flex justify-center gap-3 text-sm font-bold">
        @unless($cases->onFirstPage())<button wire:click="previousPage" class="bg-white border border-gray-200 rounded-2xl px-4 py-2">السابق</button>@endunless
        <span class="px-3 py-2 text-gray-500">صفحة {{ \App\Support\Format\ArabicDigits::convert($cases->currentPage()) }}</span>
        @if($cases->hasMorePages())<button wire:click="nextPage" class="bg-white border border-gray-200 rounded-2xl px-4 py-2">التالي</button>@endif
    </div>
</div>
