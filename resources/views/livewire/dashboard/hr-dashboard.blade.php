<div class="space-y-7">
    <x-ui.page-header :title="__('Dashboard')" :subtitle="__('Cases, deadlines, and patterns in your company')" />
    <x-ui.card class="flex flex-wrap gap-3">
        <select wire:model.live="branch" aria-label="{{ __('Branch') }}" class="ui-control w-auto"><option value="">{{ __('All branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <select wire:model.live="period" aria-label="{{ __('Period') }}" class="ui-control w-auto"><option value="3">{{ __('3 months') }}</option><option value="6">{{ __('6 months') }}</option><option value="12">{{ __('12 months') }}</option><option value="custom">{{ __('Custom period') }}</option></select>
        @if($period === 'custom')
            <x-ui.input type="date" wire:model.live="from" :aria-label="__('From')" class="w-auto" />
            <x-ui.input type="date" wire:model.live="to" :aria-label="__('To')" class="w-auto" />
        @endif
    </x-ui.card>
    @php($filterQuery = ['branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to])
    <section class="space-y-3">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Deadline alerts') }}</h2>
        @if(count($alerts))
            {{-- Departures board: every case close to or past a legal deadline, soonest first as delivered. --}}
            <div class="overflow-hidden rounded-xl bg-night-2 text-white">
                @foreach($alerts as $alert)
                    @php($label = match($alert['type']) {'procedure_approaching' => __('Procedure deadline approaches'), 'procedure_overdue' => __('Procedure deadline passed'), 'response_approaching' => __('Response deadline approaches'), 'response_missed' => __('Response deadline passed'), 'consultation_pending' => __('Legal consultation pending'), default => __('Appeal window ends soon')})
                    <a href="/cases/{{ $alert['case']->id }}" class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-4 border-t border-night-line px-5 py-3 first:border-t-0 hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-signal sm:grid-cols-[4.5rem_1fr_1fr_auto]">
                        <bdi class="font-mono text-sm font-bold text-signal">{{ \App\Support\Format\Ui::number(str_pad($alert['case']->case_number, 4, '0', STR_PAD_LEFT)) }}</bdi>
                        <span class="min-w-0"><span class="block truncate font-medium" dir="auto">{{ $alert['case']->employee?->name }}</span><span class="block truncate text-xs text-night-soft sm:hidden">{{ $label }}</span></span>
                        <span class="hidden truncate text-sm text-night-soft sm:block">{{ $label }}@if($alert['due']) · <bdi class="font-mono">{{ \App\Support\Format\Ui::date($alert['due']) }}</bdi>@endif</span>
                        <span class="flap {{ ($alert['remaining_hours'] ?? 0) < 0 ? 'flap-late' : 'flap-soon' }}">@if($alert['due']){{ $alert['remaining_hours'] >= 0 ? '' : __('Overdue').' ' }}{{ abs($alert['remaining_hours']) > 48 ? __(':count days', ['count' => \App\Support\Format\Ui::number(intdiv(abs($alert['remaining_hours']), 24))]) : __(':count hours', ['count' => \App\Support\Format\Ui::number(abs($alert['remaining_hours']))]) }}@elseif($alert['waiting_days'] !== null){{ __('Since :count days', ['count' => \App\Support\Format\Ui::number($alert['waiting_days'])]) }}@endif</span>
                    </a>
                @endforeach
            </div>
        @else
            <x-ui.card><x-ui.empty :title="__('No alerts currently.')" /></x-ui.card>
        @endif
    </section>
    <section class="space-y-3">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Metrics') }}</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach(['open' => __('Open cases'), 'closed_month' => __('Closed this month'), 'recommendation_only' => __('Recommendation only'), 'warning' => __('Warning'), 'termination' => __('Termination'), 'within15_numerator' => __('Within 15 days'), 'within15_denominator' => __('Measurable cases')] as $key => $label)
                <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => $key]) }}" class="ui-card block p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"><span class="text-sm text-gray-500">{{ $label }}</span><strong class="mt-2 block text-2xl text-primary">{{ \App\Support\Format\Ui::number($counts[$key]) }}</strong></a>
            @endforeach
            <x-ui.stat :label="__('Average procedure days')" :value="$counts['average_days'] === null ? '—' : \App\Support\Format\Ui::number((string) $counts['average_days'])" />
            <x-ui.stat :label="__('Percent within 15 days')" :value="$counts['within15_percent'] === null ? '—' : \App\Support\Format\Ui::number($counts['within15_percent']).__('%')" />
        </div>
    </section>
    <section class="space-y-3">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Repeat alerts') }}</h2>
        @forelse($thresholds as $row)
            <a class="block rounded-2xl border border-amber-100 bg-amber-light p-4 font-semibold text-amber-900" href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'threshold', 'group' => $row['group'], 'key' => $row['key'], 'violation' => $row['violation']]) }}">{{ $row['violation_label'] }} · {{ $row['label'] }} · <bdi>{{ \App\Support\Format\Ui::number($row['count']) }}</bdi></a>
        @empty
            <x-ui.card><x-ui.empty :title="__('No patterns exceeded the configured threshold.')" /></x-ui.card>
        @endforelse
    </section>
    <section class="space-y-3">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Repeat hotspots') }}</h2>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach(['branch' => __('Branches'), 'department' => __('Current department'), 'location' => __('Locations'), 'violation' => __('Violation type'), 'responsible' => __('Alleged responsible person'), 'employee' => __('Employees')] as $group => $title)
                <x-ui.card :title="$title" class="space-y-3">
                    @forelse($hotspots[$group] as $row)
                        <a href="/dashboard/cases?{{ http_build_query($filterQuery + ['metric' => 'hotspot', 'group' => $group, 'key' => $row['key']]) }}" class="block text-sm">
                            <span class="flex justify-between gap-3"><span>{{ $row['label'] }}</span><strong><bdi>{{ \App\Support\Format\Ui::number($row['count']) }}</bdi> <span class="text-gray-500">{{ $row['delta'] > 0 ? '↑' : ($row['delta'] < 0 ? '↓' : '→') }} {{ \App\Support\Format\Ui::number(abs($row['delta'])) }}</span></strong></span>
                            <span class="mt-2 block h-1.5 rounded-full bg-primary-light"><span class="block h-1.5 rounded-full bg-primary" style="width: {{ $row['width'] }}%"></span></span>
                        </a>
                    @empty
                        <x-ui.empty :title="__('No cases in this period.')" />
                    @endforelse
                </x-ui.card>
            @endforeach
        </div>
    </section>
</div>
