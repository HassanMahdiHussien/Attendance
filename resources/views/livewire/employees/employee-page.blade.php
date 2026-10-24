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
            <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>
                @foreach (['balance_date', 'remaining_days', 'change', 'upload_record'] as $column)<th class="p-2">{{ __('messages.leave_balance_ui.'.$column) }}</th>@endforeach
            </tr></thead><tbody>
                @foreach ($annualBalanceHistory as $entry)
                    <tr class="border-t"><td class="p-2" dir="ltr">{{ $entry['balance']->balance_date->toDateString() }}</td>
                        <td class="p-2" dir="ltr">{{ number_format((float) $entry['balance']->remaining_days, 2) }}</td>
                        <td class="p-2" dir="ltr">{{ $entry['change_days'] === null ? '—' : number_format((float) $entry['change_days'], 2) }}</td>
                        <td class="p-2"><a class="ui-link" href="{{ route('leave.balance.file', ['company' => $employee->company_id, 'balanceBatch' => $entry['balance']->batch_id]) }}">{{ __('messages.leave_balance_ui.download') }}</a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </x-ui.card>
    @endif
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
