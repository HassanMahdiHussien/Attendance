<div class="space-y-6">
    <x-ui.page-header :title="__('Statistical reports')" />
    <x-ui.card class="space-y-5">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Report type') }}">
            @foreach(['periodic', 'employee', 'compliance', 'hotspots'] as $key)
                @if(in_array($key, ['periodic', 'hotspots'], true) || $personReportsAvailable || auth()->user()->primary_role !== \App\Enums\UserRole::HrPartnerStaff)
                    <button type="button" wire:click="$set('type', '{{ $key }}')" class="ui-button {{ $type === $key ? 'ui-button-primary' : 'ui-button-secondary' }}" role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}">{{ __('messages.statistics.types.'.$key) }}</button>
                @endif
            @endforeach
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @if(in_array(auth()->user()->primary_role, [\App\Enums\UserRole::PlatformAdmin, \App\Enums\UserRole::HrPartnerStaff], true))
                <x-ui.field :label="__('Company')" for="stats-company"><select id="stats-company" wire:model.live="companyId" class="ui-control"><option value="">{{ auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin && in_array($type, ['periodic', 'hotspots'], true) ? __('All companies') : __('Choose a company') }}</option>@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></x-ui.field>
            @endif
            <x-ui.field :label="__('Period')" for="stats-period"><select id="stats-period" wire:model.live="period" class="ui-control"><option value="month">{{ __('Monthly') }}</option><option value="quarter">{{ __('Quarterly') }}</option><option value="custom">{{ __('Custom') }}</option></select></x-ui.field>
            @if($period !== 'custom')<x-ui.field :label="__('Year')" for="stats-year"><x-ui.input id="stats-year" wire:model.live="year" type="number" min="2000" max="2100" /></x-ui.field>@endif
            @if($period === 'month')<x-ui.field :label="__('Month')" for="stats-month"><select id="stats-month" wire:model.live="month" class="ui-control">@for($m=1;$m<=12;$m++)<option value="{{ $m }}">{{ \App\Support\Format\Ui::number($m) }}</option>@endfor</select></x-ui.field>@endif
            @if($period === 'quarter')<x-ui.field :label="__('Quarter')" for="stats-quarter"><select id="stats-quarter" wire:model.live="quarter" class="ui-control">@for($q=1;$q<=4;$q++)<option value="{{ $q }}">{{ \App\Support\Format\Ui::number($q) }}</option>@endfor</select></x-ui.field>@endif
            @if($period === 'custom')
                <x-ui.field :label="__('From')" for="stats-from"><x-ui.input id="stats-from" wire:model.live="from" type="date" /></x-ui.field>
                <x-ui.field :label="__('To')" for="stats-to"><x-ui.input id="stats-to" wire:model.live="to" type="date" /></x-ui.field>
            @endif
            @if($branches->isNotEmpty())<x-ui.field :label="__('Branch')" for="stats-branch"><select id="stats-branch" wire:model.live="branch" class="ui-control"><option value="">{{ __('All branches') }}</option>@foreach($branches as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></x-ui.field>@endif
            @if($type === 'employee')<x-ui.field :label="__('Employee')" for="stats-employee"><select id="stats-employee" wire:model.live="employeeId" class="ui-control"><option value="">{{ __('Choose an employee') }}</option>@foreach($employees as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->employee_code }}</option>@endforeach</select></x-ui.field>@endif
        </div>
        @foreach($errors->all() as $error)<p class="text-sm text-red-700" role="alert">{{ $error }}</p>@endforeach
        <div class="flex flex-wrap gap-2">
            <x-ui.button wire:click="preview">{{ __('View report') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="export('pdf')">{{ __('Export PDF') }}</x-ui.button>
            <x-ui.button variant="secondary" wire:click="export('xlsx')">{{ __('Export Excel') }}</x-ui.button>
        </div>
    </x-ui.card>
    @if($preview)
        <x-ui.card class="space-y-4">
            <h2 class="text-xl font-extrabold text-ink">{{ $preview['title'] }}</h2>
            <p class="text-sm text-gray-500">{{ __('Through the end of the period') }} · {{ \App\Support\Format\Ui::date($preview['parameters']['from']) }} — {{ \App\Support\Format\Ui::date($preview['parameters']['to']) }}</p>
            @if($preview['partial'])<p class="rounded-xl bg-amber-50 p-3">{{ __('There may be other cases you cannot see') }}</p>@endif
            @if($preview['truncated'])<p class="rounded-xl bg-amber-50 p-3">{{ __('The first 1,000 rows are shown — use Excel for the full list') }}</p>@endif
            <x-ui.stats class="sm:grid-cols-3">@foreach($preview['summary'] as $label => $value)<x-ui.stat :label="\App\Support\Format\Ui::number($label)" :value="\App\Support\Format\Ui::number((string) ($value ?? '—'))" />@endforeach</x-ui.stats>
            @foreach($preview['sections'] as $section)
                <h3 class="font-bold text-ink">{{ $section['title'] }}</h3>
                <x-ui.table>
                    <thead><tr>@foreach($section['headers'] as $header)<th scope="col" class="ui-th">{{ $header }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($section['rows'] as $row)
                            <tr>@foreach($row as $index => $cell)<x-ui.td :label="$section['headers'][$index] ?? ''">{{ \App\Support\Format\Ui::number((string) ($cell ?? '—')) }}</x-ui.td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($section['headers']) }}" class="ui-td"><x-ui.empty :title="__('No data for this period.')" /></td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            @endforeach
        </x-ui.card>
    @endif
    <x-ui.card wire:poll.5s :title="__('My exports')">
        <div class="space-y-2">
            @forelse($exports as $export)
                <div class="flex flex-wrap justify-between gap-3 border-b border-line p-2"><span>{{ __('messages.statistics.types.'.$export->report_type) }} · {{ strtoupper($export->format) }} · {{ \App\Support\Format\Ui::date($export->created_at, 'Y/m/d H:i') }}</span><span>@if($export->status === 'ready')<a class="ui-link font-semibold" href="{{ route('statistics.exports.download', $export) }}">{{ __('Download') }}</a>@else{{ __('messages.statistics.export_status.'.$export->status) }}@endif</span></div>
            @empty
                <x-ui.empty :title="__('No exports.')" />
            @endforelse
        </div>
    </x-ui.card>
</div>
