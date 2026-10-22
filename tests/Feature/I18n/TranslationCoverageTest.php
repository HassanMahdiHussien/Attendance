<?php

use App\Services\Attendance\TransactionFile;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeHistory;
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
    foreach (['initial', 'manual', 'import', 'chat'] as $source) {
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
            'pending', 'present', 'missing_punch', 'absent', 'rest_day', 'not_employed', 'pattern_missing', 'device_outage',
        ]),
        ...array_map(fn (string $field): string => 'minutes.'.$field, [
            'elapsed_minutes', 'presence_minutes', 'late_minutes', 'early_minutes',
            'exit_minutes', 'short_minutes', 'balance_minutes',
        ]),
        ...array_map(fn (string $field): string => 'policy_fields.'.$field, [
            'office_start', 'flex_minutes', 'workday_minutes', 'min_presence_minutes',
            'close_grace_minutes', 'exits_count_toward_balance', 'office_rest_days',
        ]),
        ...array_map(fn (string $field): string => 'change_fields.'.$field, [
            'work_date', 'branch_id', 'status', 'first_in', 'last_out', 'elapsed_minutes',
            'presence_minutes', 'late_minutes', 'early_minutes', 'exit_minutes',
            'short_minutes', 'balance_minutes', 'required_leave_at', 'intervals',
            'exits', 'policy_id', 'inputs', 'reviewed',
        ]),
        ...array_map(fn (string $field): string => 'inputs.'.$field, [
            'policy_version', 'work_pattern', 'hire_date', 'exit_status', 'last_working_day',
            'branch_id', 'schedule_start', 'schedule_end', 'rest_days', 'reason',
        ]),
        ...array_map(fn (string $decision): string => 'decisions_list.'.$decision, [
            'confirm_absence', 'withdraw_confirmation',
        ]),
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
