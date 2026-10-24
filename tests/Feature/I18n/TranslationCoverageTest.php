<?php

use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ExportKinds;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Attendance\TransactionFile;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeHistory;
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
            array_unique(array_merge(ExportData::DAY_COLUMNS, ExportData::SUMMARY_COLUMNS, ExportData::MEAL_COLUMNS, ExportData::ISSUE_COLUMNS))),
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
            'pending', 'present', 'missing_punch', 'shift_unresolved', 'absent', 'rest_day', 'not_employed', 'pattern_missing', 'device_outage',
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
        ]),
        ...array_map(fn (string $field): string => 'change_fields.'.$field, [
            'work_date', 'branch_id', 'status', 'first_in', 'last_out', 'elapsed_minutes',
            'presence_minutes', 'late_minutes', 'early_minutes', 'exit_minutes',
            'short_minutes', 'balance_minutes', 'required_leave_at', 'intervals',
            'exits', 'policy_id', 'inputs', 'reviewed', 'shift_start', 'shift_source',
            'shift_confidence', 'shift_evidence',
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
            'punches', 'coverage', 'exit', 'employee', 'policy', 'review', 'pending', 'unknown',
        ]),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'office_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'office_ui.'.$label))->not->toBeNull();
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
        ...array_map(fn (string $kind): string => 'explanations.'.$kind, ['leave_same_day', 'leave_credit_applied', 'unknown']),
        ...array_map(fn (string $kind): string => 'flags.'.$kind, ['unmapped_type', 'invalid_dates', 'duration_mismatch', 'missing_from_report', 'nothing_to_reconcile']),
        ...array_map(fn (string $kind): string => 'issue_types.'.$kind, ['leave_unknown_employee', 'leave_invalid_dates', 'leave_unknown_status', 'leave_invalid_duration', 'leave_duration_mismatch', 'leave_rest_day_missing', 'leave_request_missing', 'leave_with_attendance_nothing_to_reconcile']),
        ...array_map(fn (string $kind): string => 'counts.'.$kind, ['rows', 'errors', 'duplicates', 'unknown_employees', 'unmapped_types', 'invalid_dates', 'new', 'changed', 'unchanged', 'versions', 'missing_from_full', 'requests', 'through_row']),
        ...array_map(fn (string $kind): string => 'row_errors.'.$kind, ['required', 'too_long', 'invalid_dates', 'duration_mismatch', 'unknown_status', 'invalid_duration', 'missing_duration', 'column_count']),
    ];
    foreach ($labels as $label) {
        expect(data_get($english, 'leave_ui.'.$label))->not->toBeNull()
            ->and(data_get($arabic, 'leave_ui.'.$label))->not->toBeNull();
    }
});
