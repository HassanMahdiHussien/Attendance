<div class="space-y-6">
    <x-ui.page-header :title="__('Company overview')" />

    @forelse($overview as $row)
        <x-ui.card class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-ink">{{ $row['company']->name }}</h2>
                @if($row['full_access'])
                    <x-ui.button variant="secondary" href="/home/cases/{{ $row['company']->id }}">{{ __('View cases') }}</x-ui.button>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                @foreach(['open' => __('Open'), 'closed_month' => __('Closed this month'), 'recommendation_only' => __('Recommendation only'), 'warning' => __('Warnings'), 'termination' => __('Termination'), 'within15_numerator' => __('Within 15 days'), 'within15_denominator' => __('Measurable cases')] as $key => $label)
                    <x-ui.stat :label="$label" :value="\App\Support\Format\Ui::number($row['counts'][$key])" />
                @endforeach
                <x-ui.stat :label="__('Percent within 15 days')" :value="$row['counts']['within15_percent'] === null ? '—' : \App\Support\Format\Ui::number($row['counts']['within15_percent']).__('%')" />
                <x-ui.stat :label="__('Average procedure days')" :value="$row['counts']['average_days'] === null ? '—' : \App\Support\Format\Ui::number((string) $row['counts']['average_days'])" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach(['violation' => __('Violation types'), 'department' => __('Current department'), 'branch' => __('Branches'), 'location' => __('Locations')] as $group => $title)
                    <section>
                        <h3 class="mb-2 font-semibold text-ink">{{ $title }}</h3>
                        @forelse($row['hotspots'][$group] as $item)
                            <div class="flex justify-between gap-3 border-b border-line py-2 text-sm">
                                <span>{{ $item['label'] }}</span>
                                <bdi class="font-medium">{{ \App\Support\Format\Ui::number($item['count']) }}</bdi>
                            </div>
                        @empty
                            <x-ui.empty :title="__('No data.')" />
                        @endforelse
                    </section>
                @endforeach
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty :title="__('No companies are linked to this account.')" />
    @endforelse
</div>
