<div class="space-y-6">
    <x-ui.page-header :title="$employee->name" :back="route('employees.index')" />
    <x-ui.card>
        <p class="text-gray-500"><bdi dir="ltr">{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}</p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Hire date') }}: <bdi dir="ltr">{{ \App\Support\Format\Ui::date($employee->hire_date) }}</bdi></p>
    </x-ui.card>
    <section aria-label="{{ __('messages.attendance.title') }}">
        <livewire:attendance.employee-attendance :employee-id="$employee->id" />
    </section>
    <section aria-label="{{ __('messages.employee_history.title') }}">
        <x-ui.card>
            <h2 class="text-lg font-bold text-ink">{{ __('messages.employee_history.title') }}</h2>
            @forelse ($changes as $version)
                <div class="mt-3 border-t border-gray-200 pt-3">
                    <p class="text-sm text-gray-500"><bdi dir="ltr">{{ $version->effective_from->toDateString() }}</bdi> · {{ $version->actor?->name ?? __('messages.employee_history.system') }} · {{ __('messages.employee_history.sources.'.$version->source) }}</p>
                    @foreach ($version->changes as $field => $after)
                        <p class="mt-1 text-sm">{{ __('messages.employee_history.fields.'.$field) }}:
                            <bdi dir="{{ in_array($field, ['email', 'schedule_start', 'schedule_end', 'fallback_notification_email']) ? 'ltr' : 'auto' }}">{{ is_array($version->previous[$field] ?? null) ? implode(', ', $version->previous[$field]) : (($version->previous[$field] ?? null) === null ? __('messages.employee_history.empty') : $version->previous[$field]) }}</bdi>
                            →
                            <bdi dir="{{ in_array($field, ['email', 'schedule_start', 'schedule_end', 'fallback_notification_email']) ? 'ltr' : 'auto' }}">{{ is_array($after) ? implode(', ', $after) : ($after === null ? __('messages.employee_history.empty') : $after) }}</bdi>
                        </p>
                    @endforeach
                </div>
            @empty
                <p class="mt-2 text-sm text-gray-500">{{ __('messages.employee_history.none') }}</p>
            @endforelse
        </x-ui.card>
    </section>
</div>
