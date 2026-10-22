<?php

return [
    'ui' => ['months' => [1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December']],
    'attendance' => [
        'dashboard_placeholder' => 'Attendance dashboard — coming in spec 505',
        'title' => 'Attendance',
        'details_placeholder' => 'Attendance details will appear here.',
        'welcome' => 'Attendance and leave reconciliation',
    ],
    'setup' => [
        'title' => 'Finish setting up your company',
        'languages' => 'Interface languages',
        'done' => 'Done',
        'fix' => 'Complete setup',
        'branch_missing' => 'Add at least one branch.',
        'jurisdiction_missing' => 'Branch :branch needs a jurisdiction.',
        'languages_missing' => 'Arabic and English must be enabled.',
    ],
    'search' => [
        'open_search' => 'Search workspace',
        'placeholder' => 'Search employees',
        'shortcut' => 'Ctrl K',
        'employees' => 'Employees',
        'no_results' => 'No matching results.',
    ],
    'company' => [
        'arabic_english_required' => 'Arabic and English must be enabled.',
    ],
    'employee' => [
        'gender_invalid' => 'Gender must be male, female, or unspecified.',
    ],
    'employee_history' => [
        'title' => 'Changes',
        'none' => 'No changes recorded.',
        'empty' => 'Empty',
        'system' => 'System',
        'rest_days_invalid' => 'Rest days must be weekdays from 1 to 7.',
        'required_identity' => 'This identity field is required.',
        'invalid_reference' => 'The selected employee or branch is not in this company.',
        'invalid_pattern' => 'Choose a valid work pattern.',
        'sources' => ['initial' => 'Initial', 'manual' => 'Manual', 'import' => 'Import', 'chat' => 'Chat'],
        'fields' => [
            'name' => 'Name', 'name_en' => 'English name', 'department' => 'Department',
            'department_en' => 'English department', 'position' => 'Position', 'position_en' => 'English position',
            'hire_date' => 'Hire date', 'branch_id' => 'Branch', 'email' => 'Email address', 'gender' => 'Gender',
            'work_pattern' => 'Work pattern', 'rest_days' => 'Rest days', 'schedule_start' => 'Schedule start',
            'schedule_end' => 'Schedule end', 'manager_employee_id' => 'Manager', 'manager_name' => 'Reported manager name',
            'fallback_notification_email' => 'Fallback notification email',
        ],
    ],
    'exit_review' => [
        'inactive' => 'Only active employees can enter exit review.',
        'already_confirmed' => 'A confirmed exit cannot be reopened.',
        'reason_required' => 'A dismissal reason is required.',
    ],
    'import' => [
        'file_empty_or_too_large' => 'The file is empty or exceeds 2 MB.',
        'encoding_unreadable' => 'The file encoding could not be read.',
        'headers_or_separator_invalid' => 'File headers are missing or duplicated, or the separator could not be determined.',
        'row_limit' => 'The maximum is 5,000 rows.',
        'no_employees' => 'The file contains no employees.',
    ],
    'employee_import' => [
        'wrong_column_count' => 'The number of columns is incorrect',
        'field_required' => ':field is required',
        'field_too_long' => ':field exceeds 255 characters',
        'branch_unknown' => 'Unknown branch',
        'branch_ambiguous' => 'The branch name is ambiguous',
        'hire_date_invalid' => 'Invalid hire date',
        'email_invalid' => 'Invalid email address',
        'gender_invalid' => 'Invalid gender',
        'linked_user_permissions_unchanged' => 'The linked user’s permissions do not change',
        'duplicate_code' => 'Duplicate code in the file',
        'file_changed' => 'Preview again: the file changed.',
        'data_changed' => 'The data changed during import; preview again',
        'fields' => [
            'name' => 'Name', 'department' => 'Department', 'position' => 'Position',
            'hire_date' => 'Hire date', 'branch' => 'Branch',
        ],
    ],
    'locale' => [
        'arabic' => 'Arabic',
    ],
    'user' => [
        'last_active_hr_required' => 'The last active HR user in the company cannot be removed.',
    ],
];
