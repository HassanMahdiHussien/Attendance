<div class="space-y-6">
    <x-ui.page-header :title="$employee->name" :back="route('employees.index')" />
    <x-ui.card>
        <p class="text-gray-500"><bdi>{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Hire date') }}: <bdi>{{ \App\Support\Format\Ui::date($employee->hire_date) }}</bdi></p>
        @if($open)
            <div class="mt-4 rounded-xl bg-primary-light p-3">
                {{ __('Open case') }}: <a class="ui-link" href="{{ route('cases.show', $open) }}">{{ \App\Support\Format\Ui::number($open->case_number) }}</a> · {{ \App\Support\Format\Ui::stage($open->status) }}
            </div>
        @endif
    </x-ui.card>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach(['total' => __('Total cases'), 'warnings' => __('Warnings'), 'last_year' => __('Last 12 months')] as $key => $label)
            <x-ui.stat :label="$label" :value="\App\Support\Format\Ui::number($counts[$key])" />
        @endforeach
    </div>
    @if($partial)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('There may be other cases you cannot see') }}</p>@endif
    @foreach($sections as $section)
        @if($section === 'disciplinary_history')
            <x-ui.card :title="__('Disciplinary history')" class="space-y-3">
                @forelse($history as $case)
                    <article class="border-b border-line py-3 last:border-0">
                        <a class="ui-link font-semibold" href="{{ route('cases.show', $case) }}">{{ __('Case') }} {{ \App\Support\Format\Ui::number($case->case_number) }}</a>
                        <span class="text-sm text-gray-500">· {{ \App\Support\Format\Violation::name($case) }} · {{ \App\Support\Format\Ui::outcome($case->outcome) }} · {{ \App\Support\Format\Ui::date($case->violation_time) }}</span>
                        @foreach($case->documents as $document)
                            <a class="ui-link mt-1 block text-sm" href="{{ route('documents.pdf', $document) }}">{{ $document->document_type->value }} · PDF</a>
                        @endforeach
                    </article>
                @empty
                    <x-ui.empty :title="__('No prior cases are visible.')" />
                @endforelse
            </x-ui.card>
        @endif
    @endforeach
</div>
