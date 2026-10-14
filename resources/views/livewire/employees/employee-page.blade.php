<div class="space-y-6">
    <x-ui.page-header :title="$employee->name" :back="route('employees.index')" />
    <x-ui.card>
        <p class="text-gray-500"><bdi>{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Hire date') }}: <bdi>{{ \App\Support\Format\Ui::date($employee->hire_date) }}</bdi></p>
    </x-ui.card>
    <x-ui.stats class="sm:grid-cols-3">
        @foreach(['total' => __('Total cases'), 'warnings' => __('Warnings'), 'last_year' => __('Last 12 months')] as $key => $label)
            <x-ui.stat :label="$label" :value="\App\Support\Format\Ui::number($counts[$key])" />
        @endforeach
    </x-ui.stats>
    @if($partial)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('There may be other cases you cannot see') }}</p>@endif
    @foreach($sections as $section)
        @if($section === 'disciplinary_history')
            <section class="space-y-3" aria-label="{{ __('Disciplinary history') }}">
                <h2 class="text-lg font-black">{{ __('Disciplinary history') }}</h2>
                @forelse($cases as $case)
                    <x-ui.card class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a class="ui-link font-mono font-semibold" href="{{ route('cases.show', $case) }}">{{ __('Case') }} {{ \App\Support\Format\Ui::number(str_pad($case->case_number, 4, '0', STR_PAD_LEFT)) }}</a>
                            <x-ui.badge :tone="$case->status === \App\Enums\CaseStatus::ArchivedFinal ? 'green' : 'indigo'">{{ $case->status === \App\Enums\CaseStatus::ArchivedFinal ? \App\Support\Format\Ui::outcome($case->outcome) : \App\Support\Format\Ui::stage($case->status) }}</x-ui.badge>
                        </div>
                        <p class="text-sm text-gray-600">{{ \App\Support\Format\Ui::date($case->violation_time) }} · {{ \App\Support\Format\Violation::name($case) }} · {{ $case->location?->name ?? $case->violation_place_freetext }}</p>
                        @if($case->status !== \App\Enums\CaseStatus::ArchivedFinal)<x-ui.stepper :step="\App\Support\Format\StageIndicator::step($case->status)" />@endif
                        <p class="text-sm text-gray-700" dir="auto">{{ \Illuminate\Support\Str::limit((string) $case->description, 200) }}</p>
                        @php($documentItems = $case->documents->map(fn ($row) => \App\Support\Files\FileItem::forDocument($row))->all())
                        @foreach($case->documents as $document)
                            <div class="flex flex-wrap items-center gap-2 border-t border-line pt-2 text-sm"><a class="ui-link" href="{{ route('documents.pdf', $document) }}">{{ $documentItems[$loop->index]['name'] }} · PDF</a><x-ui.file-action :item="$documentItems[$loop->index]" :items="$documentItems" :index="$loop->index" :show-download="false" /></div>
                        @endforeach
                    </x-ui.card>
                @empty
                    <x-ui.card><x-ui.empty :title="__('No prior cases are visible.')" /></x-ui.card>
                @endforelse
            </section>
        @endif
    @endforeach
</div>
