<div class="space-y-6">
    <x-ui.page-header :title="$employee->name" :back="route('employees.index')" />
    <x-ui.card>
        <p class="text-gray-500"><bdi>{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Hire date') }}: <bdi>{{ \App\Support\Format\Ui::date($employee->hire_date) }}</bdi></p>
    </x-ui.card>
    <section aria-label="{{ __('messages.attendance.title') }}">
        <x-ui.card>
            <h2 class="text-lg font-bold text-ink">{{ __('messages.attendance.title') }}</h2>
            <p class="mt-2 text-sm text-gray-500">{{ __('messages.attendance.details_placeholder') }}</p>
        </x-ui.card>
    </section>
</div>
