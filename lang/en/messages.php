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
