<?php

use App\Services\Attendance\Ai\ChatTools;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ExportKinds;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Attendance\TransactionFile;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveFile;
use Illuminate\Support\Facades\File;

test('all UI translation keys exist in both catalogue layers and UI code contains no Arabic prose', function (): void {
    $paths = [];
    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
            $paths[] = $file->getPathname();
        }
    }

    $json = json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $violations = [];
    foreach ($paths as $path) {
        $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        $source = File::get($path);
        preg_match_all('/(?:__|trans|@lang)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
        foreach ($matches[1] as $key) {
            $group = str_contains($key, '.') ? strstr($key, '.', true) : '';
            if ($group !== '' && preg_match('/^[a-z_]+$/', $group) && is_file(lang_path("en/{$group}.php"))) {
                // Structured groups must exist in both languages.
                $nested = rtrim(substr($key, strlen($group) + 1), '.');
                $englishGroup = $group === 'messages' ? $english : File::getRequire(lang_path("en/{$group}.php"));
                $arabicGroup = $group === 'messages' ? $arabic
                    : (is_file(lang_path("ar/{$group}.php")) ? File::getRequire(lang_path("ar/{$group}.php")) : []);
                if (data_get($englishGroup, $nested) === null || data_get($arabicGroup, $nested) === null) {
                    $violations[] = $relative.': missing structured key '.$key;
                }
            } elseif (! str_contains($key, '::') && ! array_key_exists($key, $json)) {
                $violations[] = $relative.': missing JSON key '.$key;
            }
        }
        foreach (preg_split('/\R/u', $source) as $number => $line) {
            if (! preg_match('/\p{Arabic}/u', $line)) {
                continue;
            }
            $allowed = match ($relative) {
                'app/Support/Text/ArabicText.php', 'app/Support/Search/SearchText.php' => str_contains($line, 'strtr('),
                'app/Services/Employees/CsvReader.php' => preg_match('/^\s*\'[a-z_]+\' => \[/', $line) === 1,
                'app/Services/Employees/EmployeeImport.php' => preg_match('/^\s*\'(?:male|female)\', \'.+\' =>/', $line) === 1,
                default => false,
            };
            if (! $allowed) {
                $violations[] = $relative.':'.($number + 1).': Arabic UI text';
            }
        }
    }

    expect($violations)->toBe([]);
});

test('dynamic employee history labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (EmployeeHistory::FIELDS as $field) {
        expect(data_get($english, 'employee_history.fields.'.$field))->not->toBeNull()
            ->and(data_get($arabic, 'employee_history.fields.'.$field))->not->toBeNull();
    }
    foreach (['initial', 'manual', 'import', 'chat', 'rule'] as $source) {
        expect(data_get($english, 'employee_history.sources.'.$source))->not->toBeNull()
            ->and(data_get($arabic, 'employee_history.sources.'.$source))->not->toBeNull();
    }
});

test('assistant proposal states and reasons exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (['proposed', 'applied', 'cancelled', 'refused'] as $status) {
        expect(data_get($english, 'chat_ui.statuses.'.$status))->not->toBeNull()
            ->and(data_get($arabic, 'chat_ui.statuses.'.$status))->not->toBeNull();
    }
    foreach (['stale', 'invalid_values', 'user_cancelled'] as $reason) {
        expect(data_get($english, 'chat_ui.reasons.'.$reason))->not->toBeNull()
            ->and(data_get($arabic, 'chat_ui.reasons.'.$reason))->not->toBeNull();
    }
    foreach (range(1, 7) as $weekday) {
        expect(data_get($english, 'office_ui.weekdays.'.$weekday))->not->toBeNull()
            ->and(data_get($arabic, 'office_ui.weekdays.'.$weekday))->not->toBeNull();
    }
    foreach (ChatTools::NAMES as $name) {
        expect(data_get($english, 'chat_ui.tool_names.'.$name))->not->toBeNull()
            ->and(data_get($arabic, 'chat_ui.tool_names.'.$name))->not->toBeNull();
    }
    foreach (['queued', 'running', 'completed', 'failed', 'file_deleted', 'unavailable'] as $state) {
        expect(data_get($english, 'chat_ui.report_states.'.$state))->not->toBeNull()
            ->and(data_get($arabic, 'chat_ui.report_states.'.$state))->not->toBeNull();
    }
    foreach (['unavailable', 'refusal', 'step_limit', 'max_tokens', 'model_context_window_exceeded',
        'model_error', 'failed'] as $state) {
        expect(data_get($english, 'chat_ui.terminal.'.$state))->not->toBeNull()
            ->and(data_get($arabic, 'chat_ui.terminal.'.$state))->not->toBeNull();
    }
});

test('mapped import fields and statuses exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    foreach (ColumnMapping::FIELDS as $field) {
        expect(data_get($english, 'employee_import_ui.fields.'.$field))->not->toBeNull()
            ->and(data_get($arabic, 'employee_import_ui.fields.'.$field))->not->toBeNull();
    }
    foreach (['new', 'update', 'unchanged', 'error'] as $status) {
        expect(data_get($english, 'employee_import_ui.status.'.$status))->not->toBeNull()
            ->and(data_get($arabic, 'employee_import_ui.status.'.$status))->not->toBeNull();
    }
});

test('pattern review issue labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (['attendance_import', 'attendance_ui'] as $group) {
        expect(data_get($english, $group.'.issue_types.pattern_review'))->not->toBeNull()
            ->and(data_get($arabic, $group.'.issue_types.pattern_review'))->not->toBeNull();
    }
});

test('notification mail dynamic labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (['absence', 'late', 'early', 'exit', 'missing_punch'] as $kind) {
        expect(data_get($english, 'follow_up_mail.issue_types.'.$kind))->not->toBeNull()
            ->and(data_get($arabic, 'follow_up_mail.issue_types.'.$kind))->not->toBeNull();
    }
    foreach (['manager', 'approver'] as $kind) {
        expect(data_get($english, 'follow_up_mail.actions.'.$kind))->not->toBeNull()
            ->and(data_get($arabic, 'follow_up_mail.actions.'.$kind))->not->toBeNull();
    }
});

test('follow-up screen states, events and items exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $value): string => 'states.'.$value,
            ['open', 'waiting_manager', 'waiting_approver', 'waiting_hr', 'closed']),
        ...array_map(fn (string $value): string => 'items.'.$value,
            ['absence', 'late', 'early', 'exit', 'missing_punch']),
        ...array_map(fn (string $value): string => 'events.'.$value,
            ['opened', 'changed', 'closed', 'reopened', 'reserved', 'excluded', 'cancelled', 'skipped_stale', 'sent', 'reminded']),
        ...array_map(fn (string $value): string => 'routes.'.$value, ['manager', 'approver']),
        ...array_map(fn (string $value): string => 'batch_states.'.$value,
            ['draft', 'sending', 'sent', 'partly_failed', 'cancelled']),
        ...array_map(fn (string $value): string => 'message_states.'.$value,
            ['draft', 'excluded', 'queued', 'sending', 'sent', 'failed', 'uncertain', 'skipped']),
        ...array_map(fn (string $value): string => 'attempt_results.'.$value, ['started', 'sent', 'failed']),
        ...array_map(fn (string $value): string => 'delivery_errors.'.$value,
            ['mail_delivery_failed', 'uncertain_delivery']),
        ...array_map(fn (string $value): string => 'closure_kinds.'.$value,
            ['manual', 'reconciliation', 'reassessment']),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'follow_up_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'follow_up_ui.'.$label))->not->toBeNull();
    }
});

test('holiday issue labels exist wherever attendance and leave render them', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (['attendance_import', 'attendance_ui', 'leave_ui'] as $group) {
        expect(data_get($english, $group.'.issue_types.leave_counted_holiday'))->not->toBeNull()
            ->and(data_get($arabic, $group.'.issue_types.leave_counted_holiday'))->not->toBeNull();
    }
});

test('entitlement issue labels exist wherever attendance and leave render them', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (['attendance_import', 'attendance_ui', 'leave_ui'] as $group) {
        expect(data_get($english, $group.'.issue_types.leave_entitlement_exceeded'))->not->toBeNull()
            ->and(data_get($arabic, $group.'.issue_types.leave_entitlement_exceeded'))->not->toBeNull();
    }
});

test('holiday screen dynamic labels exist in English and Arabic', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $status): string => 'statuses.'.$status,
            ['draft', 'approved', 'rejected', 'withdrawn']),
        ...array_map(fn (string $status): string => 'ai_statuses.'.$status,
            ['pending', 'done', 'failed', 'unavailable']),
        ...array_map(fn (string $pattern): string => 'patterns.'.$pattern,
            ['office', 'shift', 'security']),
        ...array_map(fn (string $confidence): string => 'confidence.'.$confidence,
            ['high', 'medium', 'low']),
        ...array_map(fn (string $field): string => 'fields.'.$field,
            ['name_en', 'name_ar', 'ranges', 'legal_entities', 'branch_ids', 'work_patterns',
                'exceptions_note', 'status', 'source_id']),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'holiday_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'holiday_ui.'.$label))->not->toBeNull();
    }
    foreach (['unsupported_file', 'empty_file', 'request_too_large', 'invalid_image', 'invalid_pdf',
        'encrypted_pdf', 'too_many_pages', 'pdf_render_failed', 'no_key', 'daily_cap', 'refused',
        'truncated', 'unexpected_stop', 'invalid_json', 'invalid_schema', 'read_failed', 'connection',
        'rate_limited', 'api_unavailable', 'api_error', 'sdk_error'] as $code) {
        expect(data_get($english, 'holidays.ai_errors.'.$code))->not->toBeNull()
            ->and(data_get($arabic, 'holidays.ai_errors.'.$code))->not->toBeNull();
    }
});

test('every attendance overview tile has English and Arabic labels', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (ReportTiles::KEYS as $tile) {
        expect(data_get($english, 'reporting_ui.tiles.'.$tile))->not->toBeNull()
            ->and(data_get($arabic, 'reporting_ui.tiles.'.$tile))->not->toBeNull();
    }
});

test('attendance export kinds, states and columns have English and Arabic labels', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $kind): string => 'kinds.'.$kind, ExportKinds::ALL),
        ...array_map(fn (string $state): string => 'states.'.$state, ['queued', 'running', 'completed', 'failed', 'file_deleted']),
        ...array_map(fn (string $error): string => 'errors.'.$error, ['chrome_missing', 'generation_failed']),
        ...array_map(fn (string $column): string => 'columns.'.$column,
            array_unique(array_merge(ExportData::DAY_COLUMNS, ExportData::SUMMARY_COLUMNS, ExportData::MEAL_COLUMNS,
                ExportData::ISSUE_COLUMNS, ExportData::OVERTIME_COLUMNS, ExportData::ENTITLEMENT_COLUMNS,
                ExportData::CASE_COLUMNS))),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'export_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'export_ui.'.$label))->not->toBeNull();
    }
});

test('attendance screen labels for dynamic states and fields exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $field): string => 'fields.'.$field, TransactionFile::FIELDS),
        ...array_map(fn (string $state): string => 'states.'.$state, ['queued', 'processing', 'completed', 'failed']),
        ...array_map(fn (string $state): string => 'device_states.'.$state, ['pending', 'approved', 'retired']),
        ...array_map(fn (string $state): string => 'punch_states.'.$state, ['used', 'repeat', 'unused_device', 'non_employee']),
        ...array_map(fn (string $role): string => 'roles.'.$role, ['check_in', 'check_out', 'restaurant', 'retired']),
        ...array_map(fn (string $state): string => 'issue_states.'.$state, ['open', 'resolved', 'dismissed']),
        ...array_map(fn (string $reason): string => 'reasons.'.$reason, ['repeated_check_in', 'repeated_check_out', 'device_not_approved', 'device_retired', 'person_not_employee']),
        ...array_map(fn (string $failure): string => 'failures.'.$failure, ['invalid_file', 'stored_file_unavailable', 'processing_failed']),
        ...array_map(fn (string $count): string => 'count_labels.'.$count, ['rows', 'new', 'existing', 'overlap', 'duplicate_in_file', 'errors', 'restaurant', 'through_row', 'unknown_ids', 'non_employee_ids', 'new_devices']),
        ...array_map(fn (string $field): string => 'row_errors.'.$field.'_too_long', TransactionFile::FIELDS),
        ...array_map(fn (string $field): string => 'row_errors.'.$field.'_required', ['person_code', 'punch_date', 'punch_time', 'punch_state', 'serial_number']),
        ...array_map(fn (string $code): string => 'row_errors.'.$code, ['column_count', 'identifier_too_long', 'punch_state_invalid', 'date_time_invalid']),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'attendance_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'attendance_ui.'.$label))->not->toBeNull();
    }
});

test('office attendance dynamic labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $state): string => 'statuses.'.$state, [
            'pending', 'present', 'missing_punch', 'shift_unresolved', 'absent', 'rest_day', 'rest_day_worked',
            'holiday', 'holiday_worked', 'not_employed', 'pattern_missing', 'device_outage',
        ]),
        ...array_map(fn (string $field): string => 'minutes.'.$field, [
            'elapsed_minutes', 'presence_minutes', 'late_minutes', 'early_minutes',
            'exit_minutes', 'short_minutes', 'balance_minutes',
        ]),
        ...array_map(fn (string $field): string => 'policy_fields.'.$field, [
            'office_start', 'flex_minutes', 'workday_minutes', 'min_presence_minutes',
            'absence_minutes', 'close_grace_minutes', 'exits_count_toward_balance', 'office_rest_days',
            'shift_starts', 'shift_minutes', 'shift_break_minutes', 'shift_min_presence_minutes',
            'shift_rest_days', 'security_min_presence_minutes', 'inference_tolerance_minutes',
            'overtime_min_minutes', 'overtime_step_minutes', 'rest_day_half_minutes', 'rest_day_full_minutes',
            'compensatory_rest_days',
        ]),
        ...array_map(fn (string $field): string => 'change_fields.'.$field, [
            'work_date', 'branch_id', 'status', 'first_in', 'last_out', 'elapsed_minutes',
            'presence_minutes', 'late_minutes', 'early_minutes', 'exit_minutes',
            'short_minutes', 'balance_minutes', 'required_leave_at', 'intervals',
            'exits', 'policy_id', 'inputs', 'reviewed', 'shift_start', 'shift_source',
            'shift_confidence', 'shift_evidence', 'holiday_ids', 'overtime_minutes',
            'overtime_basis', 'early_arrival_minutes', 'entitlement',
        ]),
        ...array_map(fn (string $field): string => 'inputs.'.$field, [
            'policy_version', 'work_pattern', 'hire_date', 'exit_status', 'last_working_day',
            'branch_id', 'schedule_start', 'schedule_end', 'rest_days', 'reason',
        ]),
        ...array_map(fn (string $decision): string => 'decisions_list.'.$decision, [
            'confirm_absence', 'withdraw_confirmation', 'pick_shift', 'withdraw_shift',
        ]),
        ...array_map(fn (string $source): string => 'shift_sources.'.$source, ['inferred', 'hr', 'unknown']),
        ...array_map(fn (string $confidence): string => 'shift_confidences.'.$confidence, ['clear', 'leave_explained', 'unresolved']),
        ...array_map(fn (string $field): string => 'shift_evidence_fields.'.$field, ['first_in', 'last_out', 'approved_leave_minutes']),
        ...array_map(fn (string $section): string => 'policy_sections.'.$section, ['office', 'shift', 'security', 'inference']),
        ...array_map(fn (int $day): string => 'weekdays.'.$day, range(1, 7)),
        ...array_map(fn (string $trigger): string => 'triggers.'.$trigger, [
            'punches', 'coverage', 'holiday', 'exit', 'employee', 'policy', 'review', 'pending', 'unknown',
        ]),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'office_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'office_ui.'.$label))->not->toBeNull();
    }
});

test('overtime display and report labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    foreach (['after_end', 'rest_day', 'holiday'] as $basis) {
        expect(data_get($english, 'overtime_ui.basis.'.$basis))->not->toBeNull()
            ->and(data_get($arabic, 'overtime_ui.basis.'.$basis))->not->toBeNull();
    }
    foreach (['office_friday', 'shift_rest_day', 'security_rest_day'] as $basis) {
        expect(data_get($english, 'overtime_ui.entitlement_basis.'.$basis))->not->toBeNull()
            ->and(data_get($arabic, 'overtime_ui.entitlement_basis.'.$basis))->not->toBeNull();
    }
    foreach (['overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now'] as $tile) {
        expect(data_get($english, 'reporting_ui.tiles.'.$tile))->not->toBeNull()
            ->and(data_get($arabic, 'reporting_ui.tiles.'.$tile))->not->toBeNull();
    }
});

test('leave balance import errors and issue type exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    foreach (['formula_without_value', 'ambiguous_code', 'invalid_number', 'employee_code_required', 'remaining_days_required'] as $reason) {
        expect(data_get($english, 'leave_balance.reasons.'.$reason))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance.reasons.'.$reason))->not->toBeNull();
    }
    foreach (['attendance_import', 'attendance_ui'] as $group) {
        expect(data_get($english, $group.'.issue_types.leave_balance_unknown'))->not->toBeNull()
            ->and(data_get($arabic, $group.'.issue_types.leave_balance_unknown'))->not->toBeNull();
    }
});

test('leave balance screen labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    foreach (LeaveBalanceFile::FIELDS as $field) {
        expect(data_get($english, 'leave_balance_ui.fields.'.$field))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance_ui.fields.'.$field))->not->toBeNull();
    }
    foreach (['rows', 'matched', 'unknown', 'duplicate', 'invalid', 'negative', 'changed', 'unchanged', 'missing', 'imported'] as $count) {
        expect(data_get($english, 'leave_balance_ui.counts.'.$count))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance_ui.counts.'.$count))->not->toBeNull();
    }
    foreach (['queued', 'running', 'completed', 'failed'] as $state) {
        expect(data_get($english, 'leave_balance_ui.states.'.$state))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance_ui.states.'.$state))->not->toBeNull();
    }
    foreach (['unknown', 'duplicate', 'invalid'] as $outcome) {
        expect(data_get($english, 'leave_balance_ui.outcomes.'.$outcome))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance_ui.outcomes.'.$outcome))->not->toBeNull();
    }
    foreach (['invalid_file', 'stored_file_unavailable', 'processing_failed'] as $failure) {
        expect(data_get($english, 'leave_balance_ui.failures.'.$failure))->not->toBeNull()
            ->and(data_get($arabic, 'leave_balance_ui.failures.'.$failure))->not->toBeNull();
    }
});

test('leave screen dynamic labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $labels = [
        ...array_map(fn (string $field): string => 'fields.'.$field, LeaveFile::FIELDS),
        ...array_map(fn (string $field): string => 'fields.'.$field, ['employee_id', 'leave_type_id', 'status', 'flags']),
        ...array_map(fn (string $status): string => 'statuses.'.$status, ['approved', 'pending', 'draft', 'rejected', 'cancelled']),
        ...array_map(fn (string $state): string => 'states.'.$state, ['queued', 'processing', 'completed', 'failed']),
        ...array_map(fn (string $failure): string => 'failures.'.$failure, ['invalid_file', 'stored_file_unavailable', 'processing_failed']),
        ...array_map(fn (string $kind): string => 'target_kinds.'.$kind, ['absence', 'late', 'early', 'exit']),
        ...array_map(fn (string $kind): string => 'decision_kinds.'.$kind, ['cancel', 'confirm_distribution', 'fix_dates', 'reverse_deduction', 'withdraw']),
        ...array_map(fn (string $kind): string => 'consumes_values.'.$kind, ['none', 'rest_day_entitlement', 'overtime_or_credit']),
        ...array_map(fn (string $kind): string => 'explanations.'.$kind, ['leave_same_day', 'leave_credit_applied', 'rest_day_entitlement_used', 'unknown']),
        ...array_map(fn (string $kind): string => 'flags.'.$kind, ['unmapped_type', 'invalid_dates', 'duration_mismatch', 'counted_holiday', 'missing_from_report', 'nothing_to_reconcile', 'entitlement_exceeded']),
        ...array_map(fn (string $kind): string => 'issue_types.'.$kind, ['leave_unknown_employee', 'leave_invalid_dates', 'leave_unknown_status', 'leave_invalid_duration', 'leave_duration_mismatch', 'leave_counted_holiday', 'leave_rest_day_missing', 'leave_request_missing', 'leave_with_attendance_nothing_to_reconcile', 'leave_entitlement_exceeded']),
        ...array_map(fn (string $kind): string => 'counts.'.$kind, ['rows', 'errors', 'duplicates', 'unknown_employees', 'unmapped_types', 'invalid_dates', 'new', 'changed', 'unchanged', 'versions', 'missing_from_full', 'requests', 'through_row']),
        ...array_map(fn (string $kind): string => 'row_errors.'.$kind, ['required', 'too_long', 'invalid_dates', 'duration_mismatch', 'unknown_status', 'invalid_duration', 'missing_duration', 'column_count']),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'leave_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'leave_ui.'.$label))->not->toBeNull();
    }
});

test('follow-up case issue and validation labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    foreach (['approver_unmapped', 'notification_recipient_missing'] as $type) {
        expect(data_get($english, 'attendance_ui.issue_types.'.$type))->not->toBeNull()
            ->and(data_get($arabic, 'attendance_ui.issue_types.'.$type))->not->toBeNull();
    }
    foreach (['reason_required', 'invalid_approver', 'issue_resolved'] as $key) {
        expect(data_get($english, 'follow_up.'.$key))->not->toBeNull()
            ->and(data_get($arabic, 'follow_up.'.$key))->not->toBeNull();
    }
});
