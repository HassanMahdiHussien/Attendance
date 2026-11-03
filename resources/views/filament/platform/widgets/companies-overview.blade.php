<x-filament-widgets::widget>
    <x-filament::section :heading="__('Companies overview')">
        @php($companies = $this->companies())
        <div class="mb-5 grid gap-3 text-sm sm:grid-cols-2">
            <div>{{ __('Companies') }}: {{ \App\Support\Format\Ui::number(count($companies)) }}</div>
            <div>{{ __('Active companies') }}: {{ \App\Support\Format\Ui::number(count(array_filter($companies, fn ($row) => $row['active']))) }}</div>
        </div>
        <x-ui.table><thead><tr><th>{{ __('Company') }}</th><th>{{ __('Account') }}</th><th>{{ __('Subscription') }}</th></tr></thead><tbody>
            @forelse($companies as $row)<tr><x-ui.td :label="__('Company')"><a class="text-primary-600" href="{{ $row['url'] }}">{{ $row['name'] }}</a> · <a class="text-primary-600" href="{{ $row['attendance_url'] }}">{{ __('messages.reporting_ui.dashboard') }}</a></x-ui.td><x-ui.td :label="__('Account')"><x-ui.badge :tone="$row['active'] ? 'green' : 'gray'">{{ $row['active'] ? __('Active') : __('Inactive') }}</x-ui.badge></x-ui.td><x-ui.td :label="__('Subscription')"><x-ui.badge :tone="match ($row['subscription']) { 'active' => 'green', 'trial' => 'indigo', 'suspended' => 'red', default => 'gray' }">{{ match($row['subscription']) {'trial' => __('Trial'), 'active' => __('Active'), 'suspended' => __('Suspended'), default => __('Cancelled')} }}</x-ui.badge></x-ui.td></tr>
            @empty<tr><td colspan="3"><x-ui.empty :title="__('No companies.')" /></td></tr>@endforelse
        </tbody></x-ui.table>
    </x-filament::section>
</x-filament-widgets::widget>
