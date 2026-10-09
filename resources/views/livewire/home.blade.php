<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-extrabold text-ink">@php($hour = now('Asia/Baghdad')->hour){{ __($hour < 12 ? 'messages.home.greeting' : ($hour < 17 ? 'messages.home.greeting_afternoon' : 'messages.home.greeting_evening'), ['name' => auth()->user()->name]) }}</h1>
        <p class="text-sm text-gray-500"><bdi class="font-mono">{{ \App\Support\Format\Ui::date(now('Asia/Baghdad')) }}</bdi></p>
    </header>
    @if($work['role'] === 'partner')
    <x-ui.page-header :title="__('Company overview')" />

    @forelse($work['overview'] as $row)
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
                                <bdi class="font-mono font-medium">{{ \App\Support\Format\Ui::number($item['count']) }}</bdi>
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
    @else
    @if($work['role'] === 'hr')
        @if($work['setup_incomplete'])
            <x-ui.card>
                <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.setup.title') }}</h2>
                @if($work['setup_admin'])
                    @foreach($work['setup'] as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-2">
                            <span>{{ $item['reason'] }}</span>
                            <x-ui.button size="small" :href="$item['url']">{{ __('messages.setup.fix') }}</x-ui.button>
                        </div>
                    @endforeach
                @else
                    <p>{{ __('messages.setup.hr_notice') }}</p>
                @endif
            </x-ui.card>
        @endif
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                ['open', __('messages.home.open_cases'), 'open', 'gray'],
                ['procedure_due_48h', __('messages.home.due_48h'), 'procedure_due_48h', 'red'],
                ['waiting', __('messages.home.waiting_committee'), 'waiting', 'gray'],
                ['within15_percent', __('messages.home.closed_within_15'), 'within15_numerator', 'green'],
            ] as [$key, $label, $metric, $tone])
                <a href="{{ '/dashboard/cases?metric='.$metric.'&period=6' }}">
                    <x-ui.stat :label="$label" :tone="$tone" :value="$key === 'waiting' ? \App\Support\Format\Ui::number($work['counts']['waiting'] ?? 0) : ($key === 'within15_percent' ? ($work['counts'][$key] === null ? '—' : \App\Support\Format\Ui::number($work['counts'][$key]).'%') : \App\Support\Format\Ui::number($work['counts'][$key]))" />
                </a>
            @endforeach
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.home.needs_action') }}</h2>
                <div class="divide-y divide-line">
                    @forelse($work['actions'] as $row)
                        <div class="flex flex-wrap items-center gap-3 py-4">
                            @if(isset($row['case']))
                                <x-ui.badge :tone="$row['action']->deadlineAlert ?? 'indigo'"><bdi class="font-mono">{{ $row['action']->due ? \App\Support\Format\Ui::date($row['action']->due) : __('messages.home.ready') }}</bdi></x-ui.badge>
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold">{{ $row['case']->employee?->name }} <bdi class="font-mono text-xs text-gray-500">#{{ \App\Support\Format\Ui::number($row['case']->case_number) }}</bdi></div>
                                    <p class="text-sm text-gray-500">{{ $row['action']->label }}</p>
                                </div>
                                <x-ui.button size="small" :href="$row['action']->url">{{ __('messages.home.open_task') }}</x-ui.button>
                            @else
                                <x-ui.badge tone="indigo">{{ __('messages.home.new') }}</x-ui.badge>
                                <div class="min-w-0 flex-1"><div class="font-semibold">{{ $row['label'] }}</div>@if(filled($row['summary'] ?? null))<p dir="auto" class="truncate text-sm text-gray-500">{{ $row['summary'] }}</p>@endif</div>
                                <x-ui.button size="small" :href="$row['url']">{{ __('messages.home.review') }}</x-ui.button>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty :title="__('messages.home.no_actions')" />
                    @endforelse
                </div>
            </x-ui.card>
            <div class="space-y-6">
                <x-ui.card>
                    <h2 class="mb-3 font-bold text-ink">{{ __('messages.home.quick_actions') }}</h2>
                    <div class="grid grid-cols-2 gap-2">
                        <x-ui.button variant="secondary" href="/cases/new">{{ __('messages.home.new_case') }}</x-ui.button>
                        <x-ui.button variant="secondary" href="/reports/inbox">{{ __('Reports inbox') }}</x-ui.button>
                        <x-ui.button variant="secondary" href="/employees">{{ __('Employees') }}</x-ui.button>
                        <x-ui.button variant="secondary" href="/statistics">{{ __('Statistics') }}</x-ui.button>
                    </div>
                </x-ui.card>
                <x-ui.card>
                    <h2 class="mb-3 font-bold text-ink">{{ __('messages.home.hotspots') }}</h2>
                    @forelse($work['hotspots'] as $hotspot)
                        <p class="mb-2 text-sm"><x-ui.badge tone="amber"><bdi class="font-mono">{{ \App\Support\Format\Ui::number($hotspot['count']) }}</bdi></x-ui.badge> {{ $hotspot['violation'] }} · {{ $hotspot['label'] }}</p>
                    @empty
                        <x-ui.empty :title="__('messages.home.no_hotspots')" />
                    @endforelse
                </x-ui.card>
                <x-ui.card>
                    <h2 class="mb-3 font-bold text-ink">{{ __('messages.home.recent_activity') }}</h2>
                    @forelse($work['events'] as $event)
                        <p class="mb-2 text-sm text-gray-500">{{ \App\Support\Format\CaseTimeline::format($event->event_type, $event->event_type === 'status_changed' ? ['to' => $event->payload['to'] ?? ''] : []) }} · <bdi class="font-mono">#{{ \App\Support\Format\Ui::number($event->disciplinaryCase?->case_number ?? '') }}</bdi> · {{ $event->actorUser?->name ?? __('messages.home.system') }} · <bdi class="font-mono">{{ \App\Support\Format\Ui::date($event->created_at) }} {{ \App\Support\Format\Ui::date($event->created_at, 'H:i') }}</bdi></p>
                    @empty
                        <x-ui.empty :title="__('messages.home.no_activity')" />
                    @endforelse
                </x-ui.card>
            </div>
        </div>
    @elseif($work['role'] === 'committee')
        <x-ui.card>
            <h2 class="mb-3 text-lg font-bold">{{ __('messages.home.awaiting_approval') }}</h2>
            @forelse($work['documents'] as $document)
                <div class="flex flex-wrap items-center gap-3 border-b border-line py-3">
                    <span class="flex-1">{{ __('messages.home.approve_recommendations') }} · <bdi class="font-mono">#{{ \App\Support\Format\Ui::number($document->disciplinaryCase->case_number) }}</bdi></span>
                    <x-ui.button size="small" :href="'/committee/approvals/'.$document->id">{{ __('messages.home.review') }}</x-ui.button>
                </div>
            @empty
                <x-ui.empty :title="__('messages.home.no_actions')" />
            @endforelse
        </x-ui.card>
        <x-ui.card>
            <h2 class="mb-3 font-bold">{{ __('messages.home.attended_cases') }}</h2>
            @foreach($work['cases'] as $case)
                <p class="py-2"><a href="{{ '/cases/'.$case->id }}"><bdi class="font-mono">#{{ \App\Support\Format\Ui::number($case->case_number) }}</bdi> · {{ $case->employee?->name }}</a></p>
            @endforeach
        </x-ui.card>
        <x-ui.card><h2 class="mb-3 font-bold">{{ __('messages.home.quick_actions') }}</h2><x-ui.button variant="secondary" href="/committee">{{ __('Committee') }}</x-ui.button></x-ui.card>
    @elseif($work['role'] === 'reviewer')
        <x-ui.card>
            <h2 class="mb-3 text-lg font-bold">{{ __('messages.home.awaiting_opinion') }}</h2>
            @forelse($work['cases'] as $case)
                <div class="flex flex-wrap items-center gap-3 border-b border-line py-3">
                    <x-ui.badge tone="amber"><bdi class="font-mono">{{ \App\Support\Format\Ui::date(app(\App\Services\Cases\Deadlines::class)->procedureDue($case)) }}</bdi></x-ui.badge>
                    <span class="flex-1"><bdi class="font-mono">#{{ \App\Support\Format\Ui::number($case->case_number) }}</bdi> · {{ $case->employee?->name }}</span>
                    <x-ui.button size="small" :href="'/cases/'.$case->id.'/consultation'">{{ __('messages.home.give_opinion') }}</x-ui.button>
                </div>
            @empty
                <x-ui.empty :title="__('messages.home.no_actions')" />
            @endforelse
        </x-ui.card>
        <x-ui.card><h2 class="mb-3 font-bold">{{ __('messages.home.quick_actions') }}</h2><x-ui.button variant="secondary" href="/my-cases">{{ __('My cases') }}</x-ui.button></x-ui.card>
    @elseif($work['role'] === 'reporter')
        <x-ui.card>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">{{ __('My reports') }}</h2><x-ui.button href="/reports/new">{{ __('messages.home.submit_report') }}</x-ui.button></div>
            @forelse($work['reports'] as $report)
                <div class="flex justify-between gap-2 border-b border-line py-3"><span>{{ __('Report') }} <bdi class="font-mono">{{ \App\Support\Format\Ui::number($report->id) }}</bdi></span><x-ui.badge>{{ match($report->status) { \App\Enums\ReportStatus::Received => __('Received'), \App\Enums\ReportStatus::UnderReview => __('Under review'), default => __('Closed') } }}</x-ui.badge></div>
            @empty
                @if($work['emails']->isEmpty())<x-ui.empty :title="__('You have not submitted a report yet.')" />@endif
            @endforelse
            @foreach($work['emails'] as $email)
                <div class="flex justify-between gap-2 border-b border-line py-3"><span>{{ __('Forwarded email') }} <bdi class="font-mono">{{ \App\Support\Format\Ui::number($email->id) }}</bdi></span><x-ui.badge>{{ $email->status === \App\Enums\EmailDraftStatus::Received ? __('Received') : ($email->status === \App\Enums\EmailDraftStatus::Dismissed ? __('Closed') : __('Under review')) }}</x-ui.badge></div>
            @endforeach
        </x-ui.card>
    @endif
    @endif
</div>
