<div class="space-y-7">
    <div><h1 class="text-2xl font-black">{{ __('Dashboard') }}</h1><p class="text-sm text-gray-400 mt-1">{{ __('Cases, deadlines, and patterns in your company') }}</p></div>
    <div class="bg-white rounded-2xl p-4 flex flex-wrap gap-3 shadow-sm">
        <select wire:model.live="branch" aria-label="{{ __('Branch') }}" class="rounded-xl border border-gray-200 p-3"><option value="">{{ __('All branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <select wire:model.live="period" aria-label="{{ __('Period') }}" class="rounded-xl border border-gray-200 p-3"><option value="3">{{ __('3 months') }}</option><option value="6">{{ __('6 months') }}</option><option value="12">{{ __('12 months') }}</option><option value="custom">{{ __('Custom period') }}</option></select>
        @if($period === 'custom')<input type="date" wire:model.live="from" aria-label="{{ __('From') }}" class="rounded-xl border border-gray-200 p-3"><input type="date" wire:model.live="to" aria-label="{{ __('To') }}" class="rounded-xl border border-gray-200 p-3">@endif
    </div>
    @php($filterQuery = ['branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to])
    <section><h2 class="text-lg font-black mb-3">{{ __('Deadline alerts') }}</h2><div class="space-y-2">
        @forelse($alerts as $alert)
            @php($label = match($alert['type']) {'procedure_approaching' => __('Procedure deadline approaches'), 'procedure_overdue' => __('Procedure deadline passed'), 'response_approaching' => __('Response deadline approaches'), 'response_missed' => __('Response deadline passed'), 'consultation_pending' => __('Legal consultation pending'), default => __('Appeal window ends soon')})
            <a href="/cases/{{ $alert['case']->id }}" class="block bg-red-light text-red rounded-2xl p-4 font-bold">{{ $label }} · <span class="font-mono">{{ \App\Support\Format\Ui::number($alert['case']->case_number) }}</span> · {{ $alert['case']->employee?->name }} @if($alert['due']) · {{ \App\Support\Format\Ui::date($alert['due']) }} · {{ $alert['remaining_hours'] >= 0 ? __('Remaining') : __('Overdue') }} {{ abs($alert['remaining_hours']) > 48 ? __(':count days', ['count' => \App\Support\Format\Ui::number(intdiv(abs($alert['remaining_hours']), 24))]) : __(':count hours', ['count' => \App\Support\Format\Ui::number(abs($alert['remaining_hours']))]) }} @endif @if($alert['waiting_days'] !== null) · {{ __('Since :count days', ['count' => \App\Support\Format\Ui::number($alert['waiting_days'])]) }} @endif</a>
        @empty<p class="bg-white rounded-2xl p-6 text-gray-500">{{ __('No alerts currently.') }}</p>@endforelse
    </div></section>
    <section><h2 class="text-lg font-black mb-3">{{ __('Metrics') }}</h2><div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach(['open' => __('Open cases'), 'closed_month' => __('Closed this month'), 'recommendation_only' => __('Recommendation only'), 'warning' => __('Warning'), 'termination' => __('Termination'), 'within15_numerator' => __('Within 15 days'), 'within15_denominator' => __('Measurable cases')] as $key => $label)
            <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => $key]) }}" class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">{{ $label }}</span><strong class="block text-2xl text-primary mt-2">{{ \App\Support\Format\Ui::number($counts[$key]) }}</strong></a>
        @endforeach
        <div class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">{{ __('Average procedure days') }}</span><strong class="block text-2xl text-primary mt-2">{{ $counts['average_days'] === null ? '—' : \App\Support\Format\Ui::number((string) $counts['average_days']) }}</strong></div>
        <div class="bg-white rounded-2xl p-5 shadow-sm"><span class="text-sm text-gray-500">{{ __('Percent within 15 days') }}</span><strong class="block text-2xl text-primary mt-2">{{ $counts['within15_percent'] === null ? '—' : \App\Support\Format\Ui::number($counts['within15_percent']).__('%') }}</strong></div>
    </div></section>
    <section><h2 class="text-lg font-black mb-3">{{ __('Repeat alerts') }}</h2><div class="space-y-2">@forelse($thresholds as $row)
        <a class="block bg-amber-light text-amber rounded-2xl p-4 font-bold" href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'threshold', 'group' => $row['group'], 'key' => $row['key'], 'violation' => $row['violation']]) }}">{{ $row['violation'] }} · {{ $row['label'] }} · {{ \App\Support\Format\Ui::number($row['count']) }}</a>
        @empty<p class="bg-white rounded-2xl p-6 text-gray-500">{{ __('No patterns exceeded the configured threshold.') }}</p>@endforelse</div></section>
    <section><h2 class="text-lg font-black mb-3">{{ __('Repeat hotspots') }}</h2><div class="grid md:grid-cols-2 gap-4">
        @foreach(['branch' => __('Branches'), 'department' => __('Current department'), 'location' => __('Locations'), 'violation' => __('Violation type'), 'responsible' => __('Alleged responsible person'), 'employee' => __('Employees')] as $group => $title)
            <div class="bg-white rounded-2xl p-5 shadow-sm"><h3 class="font-bold mb-4">{{ $title }}</h3><div class="space-y-3">@forelse($hotspots[$group] as $row)
                <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'hotspot', 'group' => $group, 'key' => $row['key']]) }}" class="block text-sm"><span class="flex justify-between"><span>{{ $row['label'] }}</span><strong>{{ \App\Support\Format\Ui::number($row['count']) }} <span class="text-gray-400">{{ $row['delta'] > 0 ? '↑' : ($row['delta'] < 0 ? '↓' : '→') }} {{ \App\Support\Format\Ui::number(abs($row['delta'])) }}</span></strong></span><span class="block bg-primary-light rounded-full h-1.5 mt-2"><span class="block bg-primary rounded-full h-1.5" style="width: {{ $row['width'] }}%"></span></span></a>
            @empty<p class="text-gray-400">{{ __('No cases in this period.') }}</p>@endforelse</div></div>
        @endforeach
    </div></section>
</div>
