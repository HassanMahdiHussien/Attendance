<div class="space-y-6">
    <x-ui.page-header :title="$employee->name" :back="route('employees.index')" />
    <x-ui.card>
        <p class="text-gray-500">
            <bdi dir="ltr">{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->position }} · {{ $employee->branch?->name }}
            @if ($employee->staff_category)
                · {{ __('messages.employee_history.fields.staff_category') }}: <bdi dir="ltr">{{ $employee->staff_category }}</bdi>
            @endif
            @if ($employee->legal_entity)
                · {{ __('messages.employee_history.fields.legal_entity') }}: <bdi dir="auto">{{ $employee->legal_entity }}</bdi>
            @endif
        </p>
        <p class="mt-2 text-sm text-gray-500">{{ __('Hire date') }}: <bdi dir="ltr">{{ \App\Support\Format\Ui::date($employee->hire_date) }}</bdi></p>
        @if ($patternSource === 'rule')
            <p class="mt-2 text-sm text-gray-500">{{ __('Work pattern') }}: {{ __('messages.employee_history.set_by_rule') }}</p>
        @endif
        @if ($patternIssue)
            <a class="ui-link mt-2 inline-block" href="{{ \App\Filament\Admin\Pages\Attendance\AttendanceIssues::getUrl(panel: 'admin', tenant: $employee->company).'?'.http_build_query(['type' => 'pattern_review', 'subjectKey' => 'employee:'.$employee->id]) }}">{{ __('messages.employee_history.pattern_review_link') }}</a>
        @endif
    </x-ui.card>
    @if ($annualBalance)
        <x-ui.card :title="__('messages.leave_balance_ui.annual_balance')">
            <p class="text-lg font-semibold"><bdi dir="ltr">{{ number_format((float) $annualBalance->remaining_days, 2) }}</bdi> {{ __('messages.leave_balance_ui.days') }} · <bdi dir="ltr">{{ number_format((float) $annualBalance->remaining_days * 8, 2) }}</bdi> {{ __('messages.leave_balance_ui.hours') }}</p>
            <p class="text-sm text-gray-500">{{ __('messages.leave_balance_ui.balance_date') }}: <bdi dir="ltr">{{ $annualBalance->balance_date->toDateString() }}</bdi></p>
            @if (! $annualBalancePresent)<p class="mt-2 text-amber-700">{{ __('messages.leave_balance_ui.missing_latest') }}</p>@endif
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                @foreach (['carried_over_days', 'entitlement_days', 'used_days'] as $field)
                    <p>{{ __('messages.leave_balance_ui.fields.'.$field) }}: <bdi dir="ltr">{{ $annualBalance->$field === null ? '—' : number_format((float) $annualBalance->$field, 2) }}</bdi></p>
                @endforeach
            </div>
            <h3 class="mt-4 font-semibold">{{ __('messages.leave_balance_ui.history') }}</h3>
            <div class="overflow-x-auto"><x-ui.table><thead><tr>
                @foreach (['balance_date', 'remaining_days', 'change', 'upload_record'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
            </tr></thead><tbody>
                @forelse ($annualBalanceHistory as $entry)
                    <tr class="border-t"><x-ui.td :label="__('messages.leave_balance_ui.balance_date')">{{ $entry['balance']->balance_date->toDateString() }}</x-ui.td>
                        <x-ui.td :label="__('messages.leave_balance_ui.remaining_days')">{{ number_format((float) $entry['balance']->remaining_days, 2) }}</x-ui.td>
                        <x-ui.td :label="__('messages.leave_balance_ui.change')">{{ $entry['change_days'] === null ? '—' : number_format((float) $entry['change_days'], 2) }}</x-ui.td>
                        <x-ui.td :label="__('messages.leave_balance_ui.upload_record')"><a class="ui-link" href="{{ route('leave.balance.file', ['company' => $employee->company_id, 'balanceBatch' => $entry['balance']->batch_id]) }}">{{ __('messages.leave_balance_ui.download') }}</a></x-ui.td>
                    </tr>
                @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
            </tbody></x-ui.table></div>
        </x-ui.card>
    @endif
    <section aria-label="{{ __('messages.attendance.title') }}">
        <livewire:attendance.employee-attendance :employee-id="$employee->id" />
    </section>
    <livewire:attendance.employee-follow-up :employee-id="$employee->id" />
    <section aria-label="{{ __('messages.employee_history.title') }}">
        <x-ui.card>
            <h2 class="text-lg font-bold text-ink">{{ __('messages.employee_history.title') }}</h2>
            <x-ui.table class="mt-3"><thead><tr><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.office_ui.by') }}</th><th scope="col">{{ __('messages.employee_history.title') }}</th></tr></thead><tbody>
            @forelse ($changes as $entry)
                <tr>
                    <x-ui.td :label="__('messages.office_ui.date')"><bdi dir="ltr">{{ $entry['version']->effective_from->toDateString() }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.office_ui.by')">{{ $entry['version']->actor?->name ?? __('messages.employee_history.system') }} · {{ __('messages.employee_history.sources.'.$entry['version']->source) }}</x-ui.td>
                    <x-ui.td :label="__('messages.employee_history.title')">@foreach ($entry['fields'] as $field => $after)
                        <p>{{ __('messages.employee_history.fields.'.$field) }}: <bdi dir="{{ in_array($field, ['email', 'schedule_start', 'schedule_end', 'fallback_notification_email']) ? 'ltr' : 'auto' }}">{{ is_array($entry['version']->previous[$field] ?? null) ? implode(', ', $entry['version']->previous[$field]) : (($entry['version']->previous[$field] ?? null) === null ? __('messages.employee_history.empty') : $entry['version']->previous[$field]) }}</bdi> → <bdi dir="{{ in_array($field, ['email', 'schedule_start', 'schedule_end', 'fallback_notification_email']) ? 'ltr' : 'auto' }}">{{ is_array($after) ? implode(', ', $after) : ($after === null ? __('messages.employee_history.empty') : $after) }}</bdi></p>
                    @endforeach</x-ui.td>
                </tr>
            @empty <tr><td colspan="3" class="p-4"><x-ui.empty :title="__('messages.employee_history.none')" /></td></tr> @endforelse
            </tbody></x-ui.table>
        </x-ui.card>
    </section>
</div>
