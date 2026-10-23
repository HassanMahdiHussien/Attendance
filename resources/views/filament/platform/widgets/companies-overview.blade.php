<x-filament-widgets::widget>
    <x-filament::section :heading="__('Companies overview')">
        @php($companies = $this->companies())
        <div class="mb-5 grid gap-3 text-sm sm:grid-cols-2">
            <div>{{ __('Companies') }}: {{ \App\Support\Format\Ui::number(count($companies)) }}</div>
            <div>{{ __('Active companies') }}: {{ \App\Support\Format\Ui::number(count(array_filter($companies, fn ($row) => $row['active']))) }}</div>
        </div>
        <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr class="border-b"><th class="p-2">{{ __('Company') }}</th><th class="p-2">{{ __('Account') }}</th><th class="p-2">{{ __('Subscription') }}</th></tr></thead><tbody>
            @forelse($companies as $row)<tr class="border-b"><td class="p-2"><a class="text-primary-600" href="{{ $row['url'] }}">{{ $row['name'] }}</a> · <a class="text-primary-600" href="{{ $row['attendance_url'] }}">{{ __('messages.reporting_ui.dashboard') }}</a></td><td class="p-2">{{ $row['active'] ? __('Active') : __('Inactive') }}</td><td class="p-2">{{ match($row['subscription']) {'trial' => __('Trial'), 'active' => __('Active'), 'suspended' => __('Suspended'), default => __('Cancelled')} }}</td></tr>
            @empty<tr><td colspan="3" class="p-5 text-center">{{ __('No companies.') }}</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-widgets::widget>
