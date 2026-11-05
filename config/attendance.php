<?php

return [
    'non_employee_department_patterns' => ['/^3rd Party/iu', '/^Guest$/iu', '/^General$/iu'],
    'pattern_rules' => [
        ['name' => 'security_position', 'field' => 'position', 'regex' => '/security/iu', 'pattern' => 'security'],
        ['name' => 'blue_collar', 'field' => 'staff_category', 'equals' => 'B', 'pattern' => 'shift'],
        ['name' => 'white_collar', 'field' => 'staff_category', 'equals' => 'W', 'pattern' => 'office'],
    ],
    'pattern_review_positions' => ['/information security/iu', '/security manager/iu'],
    'cleanup' => ['max_group_gap_hours' => 16, 'single_device_merge_minutes' => 15],
    'preview_sample_limit' => 50,
    'max_file_bytes' => 100 * 1024 * 1024,
    'pdf' => ['chrome_path' => env('ATTENDANCE_CHROME_PATH', '/usr/local/bin/chrome-headless-shell')],
    'holidays' => ['ai_daily_cap' => 20],
    'leave_descriptions' => ['ai_daily_cap' => 80],
    'leave_entry' => ['notify_window_days' => 30],
];
