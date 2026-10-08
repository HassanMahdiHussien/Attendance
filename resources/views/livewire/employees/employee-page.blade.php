<div class="space-y-6">
    <a href="{{ route('employees.index') }}" class="text-primary text-sm font-bold">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Employees') }}</a>
    <header class="bg-white rounded-3xl p-6 shadow-sm">
        <h1 class="text-2xl font-black">{{ $employee->name }}</h1>
        <p class="text-gray-500 mt-2">{{ \App\Support\Format\Ui::number($employee->employee_code) }} · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="text-sm text-gray-500 mt-2">{{ __('Hire date') }}: {{ \App\Support\Format\Ui::date($employee->hire_date) }}</p>
        @if($open)<p class="mt-4 rounded-xl bg-primary-light p-3">{{ __('Open case') }}: <a class="text-primary font-bold" href="{{ route('cases.show', $open) }}">{{ \App\Support\Format\Ui::number($open->case_number) }}</a> · {{ \App\Support\Format\Ui::stage($open->status) }}</p>@endif
    </header>
    <div class="grid grid-cols-3 gap-3">
        @foreach(['total' => __('Total cases'), 'warnings' => __('Warnings'), 'last_year' => __('Last 12 months')] as $key => $label)
            <div class="bg-white rounded-2xl p-4"><p class="text-gray-500 text-sm">{{ $label }}</p><strong class="text-2xl">{{ \App\Support\Format\Ui::number($counts[$key]) }}</strong></div>
        @endforeach
    </div>
    @if($partial)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('There may be other cases you cannot see') }}</p>@endif
    @foreach($sections as $section)
        @if($section === 'disciplinary_history')
            <section class="space-y-3"><h2 class="text-xl font-black">{{ __('Disciplinary history') }}</h2>
                @forelse($history as $case)
                    <article class="bg-white rounded-2xl p-4">
                        <a class="text-primary font-bold" href="{{ route('cases.show', $case) }}">{{ __('Case') }} {{ \App\Support\Format\Ui::number($case->case_number) }}</a>
                        <span class="text-gray-500">· {{ $case->violation_type }} · {{ \App\Support\Format\Ui::outcome($case->outcome) }} · {{ \App\Support\Format\Ui::date($case->violation_time) }}</span>
                        @foreach($case->documents as $document)<a class="block text-sm text-primary mt-1" href="{{ route('documents.pdf', $document) }}">{{ $document->document_type->value }} · PDF</a>@endforeach
                    </article>
                @empty<p class="bg-white rounded-xl p-5 text-gray-500">{{ __('No prior cases are visible.') }}</p>@endforelse
            </section>
        @endif
    @endforeach
</div>
