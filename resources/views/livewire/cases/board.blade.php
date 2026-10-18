<div class="min-w-0 max-w-full">
    <x-ui.page-header :title="__('Case board')" :subtitle="__('Cases that need your attention')">
        <x-slot:actions><x-ui.button href="/cases/new"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('Open new case') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    @if($urgentCount > 0)
        <x-ui.alert tone="red" class="mb-5 flex items-center gap-3">
            <x-ui.icon name="alert" class="h-5 w-5 shrink-0" />
            <span><strong>{{ $urgentCount === 1 ? __('One case approaches the 15-day legal deadline') : __(':count cases approach the 15-day legal deadline', ['count' => \App\Support\Format\Ui::number($urgentCount)]) }}</strong> — {{ __('A decision is needed soon') }}</span>
        </x-ui.alert>
    @endif

    <x-ui.card class="mb-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.input wire:model.live.debounce.300ms="search" aria-label="{{ __('Search') }}" placeholder="{{ __('Search by name or case number') }}" />
            <x-ui.select wire:model.live="branch" aria-label="{{ __('Branch') }}">
                <option value="">{{ __('All branches') }}</option>
                @foreach($branches as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="stage" aria-label="{{ __('Stage') }}">
                <option value="">{{ __('All stages') }}</option>
                @foreach($stages as $number)<option value="{{ $number }}">{{ \App\Support\Format\Ui::stageLabel($number) }}</option>@endforeach
            </x-ui.select>
        </div>
        @if($search !== '' || $branch !== '' || $stage !== '')
            <div class="mt-4 flex flex-wrap items-center gap-2" aria-label="{{ __('messages.search.active_filters') }}">
                @if($search !== '')<button type="button" wire:click="removeSearchFilter" class="ui-badge-indigo rounded-full px-3 py-1">{{ __('Search') }}: {{ $search }} ×</button>@endif
                @if($branch !== '')<button type="button" wire:click="removeBranchFilter" class="ui-badge-indigo rounded-full px-3 py-1">{{ __('Branch') }}: {{ $branches->firstWhere('id', (int) $branch)?->name }} ×</button>@endif
                @if($stage !== '')<button type="button" wire:click="removeStageFilter" class="ui-badge-indigo rounded-full px-3 py-1">{{ __('Stage') }}: {{ \App\Support\Format\Ui::stageLabel((int) $stage) }} ×</button>@endif
                <button type="button" wire:click="clearFilters" class="ui-link text-sm font-bold">{{ __('messages.search.clear_filters') }}</button>
            </div>
        @endif
    </x-ui.card>

    @if($cases->count() === 0)
        <x-ui.card><x-ui.empty :title="__('No matching cases.')" icon="folder"><x-slot:action><x-ui.button href="/cases/new">{{ __('Open new case') }}</x-ui.button></x-slot:action></x-ui.empty></x-ui.card>
    @else
        <x-ui.table>
            <thead><tr><th>{{ __('Case number') }}</th><th>{{ __('Employee') }}</th><th>{{ __('Progress') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
                @foreach($cases as $case)
                    @php
                        $archived = $case->status === \App\Enums\CaseStatus::ArchivedFinal;
                        $due = $deadlines->procedureDue($case);
                        $days = $due ? (int) now('Asia/Baghdad')->startOfDay()->diffInDays($due->startOfDay(), false) : null;
                        $urgent = ! $archived && $case->decision_issued_at === null && $days !== null && $days <= 2;
                        $step = \App\Support\Format\StageIndicator::step($case->status);
                        $waiting = in_array($case->status, [\App\Enums\CaseStatus::AwaitingEmployeeResponse, \App\Enums\CaseStatus::AwaitingCommitteeSignatures], true);
                        $pill = $archived ? __('Closed') : ($urgent ? ($days < 0 ? __('Overdue') : \App\Support\Format\Ui::remaining($days)) : ($waiting ? ($case->status === \App\Enums\CaseStatus::AwaitingEmployeeResponse ? __('Awaiting response') : __('Awaiting signature')) : \App\Support\Format\Ui::stage($case->status)));
                        $tone = $archived ? 'green' : ($urgent ? 'red' : ($waiting ? 'amber' : \App\Support\Format\Tone::case($case->status)));
                    @endphp
                    <tr wire:key="case-{{ $case->id }}" class="{{ $archived ? 'opacity-60' : '' }}">
                        <x-ui.td :label="__('Case number')"><a href="/cases/{{ $case->id }}" class="ui-link font-mono text-xs font-bold"><bdi>#{{ \App\Support\Format\Ui::number(str_pad($case->case_number, 4, '0', STR_PAD_LEFT)) }}</bdi></a>@if($case->incident_id)<span class="mt-1 block text-xs text-gray-500">{{ __('Incident · :count visible cases', ['count' => \App\Support\Format\Ui::number($visibleIncidentCounts[$case->incident_id] ?? 1)]) }}</span>@endif</x-ui.td>
                        <x-ui.td :label="__('Employee')">
                            <div class="flex items-center gap-3 text-start">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-light font-bold text-primary">{{ \App\Support\Format\Initials::for($case->employee?->name ?? '') }}</span>
                                <span class="min-w-0"><a href="/cases/{{ $case->id }}" class="ui-link block font-bold text-ink">{{ $case->employee?->name }}</a><span class="block text-xs text-gray-500">{{ $case->employee?->position }} — {{ $case->employee?->department }}</span>@if($case->employee)<a class="ui-link block text-xs font-bold" href="{{ route('employees.show', $case->employee) }}">{{ __('Employee page') }}</a>@endif</span>
                            </div>
                        </x-ui.td>
                        <x-ui.td :label="__('Progress')"><div class="flex items-center" aria-label="{{ \App\Support\Format\Ui::stage($case->status) }}">@for($i = 1; $i <= 5; $i++)@if($i > 1)<span class="h-[2px] w-5 {{ $i <= $step ? ($archived ? 'bg-green' : 'bg-night') : 'bg-line' }}"></span>@endif<span class="h-2.5 w-2.5 shrink-0 rounded-full border-2 {{ $archived ? 'border-green bg-green' : ($i < $step ? 'border-night bg-night' : ($i === $step ? 'border-signal bg-signal' : 'border-line bg-white')) }}"></span>@endfor</div></x-ui.td>
                        <x-ui.td :label="__('Status')">@if($urgent)<span class="flap {{ $days < 0 ? 'flap-late' : 'flap-soon' }}">{{ $pill }}</span>@else<x-ui.badge :tone="$tone">{{ $pill }}</x-ui.badge>@endif</x-ui.td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
    <div class="mt-6 flex items-center justify-center gap-3 text-sm font-bold">
        @unless($cases->onFirstPage())<x-ui.button variant="secondary" wire:click="previousPage">{{ __('Previous') }}</x-ui.button>@endunless
        <span class="text-gray-500">{{ __('Page') }} {{ \App\Support\Format\Ui::number($cases->currentPage()) }}</span>
        @if($cases->hasMorePages())<x-ui.button variant="secondary" wire:click="nextPage">{{ __('Next') }}</x-ui.button>@endif
    </div>
</div>
