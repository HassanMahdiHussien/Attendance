<?php

return [
    'non_employee_department_patterns' => ['/^3rd Party/iu', '/^Guest$/iu', '/^General$/iu'],
    'cleanup' => ['max_group_gap_hours' => 16],
    'preview_sample_limit' => 50,
    'max_file_bytes' => 100 * 1024 * 1024,
    'pdf' => ['chrome_path' => env('ATTENDANCE_CHROME_PATH', '/usr/local/bin/chrome-headless-shell')],
];
