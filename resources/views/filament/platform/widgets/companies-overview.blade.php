<x-filament-widgets::widget>
    <x-filament::section :heading="__('Companies overview')">
        @php($companies = $this->companies())
        <div class="grid gap-3 sm:grid-cols-5 mb-5 text-sm">
            <div>{{ __('Companies') }}: {{ \App\Support\Format\Ui::number(count($companies)) }}</div>
            <div>{{ __('Active companies') }}: {{ \App\Support\Format\Ui::number(count(array_filter($companies, fn ($row) => $row['active']))) }}</div>
            <div>{{ __('Open cases') }}: {{ \App\Support\Format\Ui::number(array_sum(array_column($companies, 'open'))) }}</div>
            <div>{{ __('Past deadline') }}: {{ \App\Support\Format\Ui::number(array_sum(array_column($companies, 'past_due'))) }}</div>
            <div>{{ __('Pending consultations') }}: {{ \App\Support\Format\Ui::number(array_sum(array_column($companies, 'consultations'))) }}</div>
        </div>
        <div class="overflow-x-auto"><table class="w-full text-sm text-start"><thead><tr class="border-b"><th class="p-2">{{ __('Company') }}</th><th class="p-2">{{ __('Account') }}</th><th class="p-2">{{ __('Subscription') }}</th><th class="p-2">{{ __('Open') }}</th><th class="p-2">{{ __('Past deadline') }}</th><th class="p-2">{{ __('Pending consultations') }}</th><th class="p-2">{{ __('Last activity') }}</th></tr></thead><tbody>
            @forelse($companies as $row)<tr class="border-b"><td class="p-2"><a class="text-primary-600" href="{{ $row['url'] }}">{{ $row['name'] }}</a></td><td class="p-2">{{ $row['active'] ? __('Active') : __('Inactive') }}</td><td class="p-2">{{ match($row['subscription']) {'trial' => __('Trial'), 'active' => __('Active'), 'suspended' => __('Suspended'), default => __('Cancelled')} }}</td><td class="p-2">{{ \App\Support\Format\Ui::number($row['open']) }}</td><td class="p-2">{{ \App\Support\Format\Ui::number($row['past_due']) }}</td><td class="p-2">{{ \App\Support\Format\Ui::number($row['consultations']) }}</td><td class="p-2">{{ \App\Support\Format\Ui::date($row['last_activity']) }}</td></tr>
            @empty<tr><td colspan="7" class="p-5 text-center">{{ __('No companies.') }}</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-widgets::widget>
