@php
    // A row names what happened and who it is about, so a reporter can tell their reports apart.
    $names = $report->employees->pluck('name')->merge($report->free_employee_names ?? [])->filter()->values();
    if ($names->isEmpty() && $report->employee) {
        $names = collect([$report->employee->name]);
    }
    $place = $report->location?->name ?? $report->place_freetext;
    [$label, $tone] = match ($report->status) {
        \App\Enums\ReportStatus::Received => [__('Received'), 'indigo'],
        \App\Enums\ReportStatus::UnderReview => [__('Under review'), 'amber'],
        \App\Enums\ReportStatus::ConvertedToCase => [__('Converted to case'), 'green'],
        \App\Enums\ReportStatus::Dismissed => [__('Dismissed'), 'gray'],
    };
@endphp
<div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
    <div class="min-w-0 flex-1 space-y-1.5">
        {{-- bdi keeps an English title's own direction but its alignment follows the page, like the rest of the row. --}}
        <p class="line-clamp-2 font-semibold leading-relaxed"><bdi>{{ \Illuminate\Support\Str::limit($report->description, 160) }}</bdi></p>
        <div class="flex flex-wrap items-center gap-1.5 text-sm">
            @forelse($names->take(4) as $name)<span dir="auto" class="rounded border border-line bg-bg px-2 leading-relaxed">{{ $name }}</span>@empty<span class="text-gray-600">{{ __('Employees not named') }}</span>@endforelse
            @if($names->count() > 4)<span class="text-gray-600">+{{ $names->count() - 4 }}</span>@endif
        </div>
        <p class="text-sm text-gray-600">@if($place)<bdi dir="auto">{{ $place }}</bdi> · @endif<bdi class="font-mono text-xs">{{ \App\Support\Format\Ui::date($report->occurred_at ?? $report->created_at) }}</bdi> · {{ __('Report') }} <bdi class="font-mono text-xs">{{ \App\Support\Format\Ui::number($report->id) }}</bdi></p>
    </div>
    <x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
</div>
