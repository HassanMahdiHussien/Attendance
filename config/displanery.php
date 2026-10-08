<?php

return [
    'backups' => ['enabled' => env('BACKUPS_ENABLED', false), 'notify_email' => env('BACKUP_NOTIFY_EMAIL')],
    'restore_drill' => ['connection' => 'restore_drill', 'database' => env('DB_DRILL_DATABASE') ?: env('DB_DATABASE', 'laravel').'_restore_drill'],
    'healthchecks' => [
        'heartbeat' => env('HEALTHCHECK_HEARTBEAT_URL'),
        'backup' => env('HEALTHCHECK_BACKUP_URL'),
        'restore_drill' => env('HEALTHCHECK_RESTORE_DRILL_URL'),
        'deadline_alerts' => env('HEALTHCHECK_DEADLINE_ALERTS_URL'),
        'queue_failures' => env('HEALTHCHECK_QUEUE_FAILURES_URL'),
    ],
    'ai' => ['legal_reading_model' => env('LEGAL_READING_MODEL', 'claude-opus-5-5'),
        'reading_model' => env('SCAN_READING_MODEL', 'claude-opus-5-5'),
        'drafting_model' => env('DRAFTING_MODEL', 'claude-opus-5-5'),
        'relevance_model' => env('RELEVANCE_MODEL', 'claude-opus-5-5'),
        'max_candidates' => 30],
    'legal_library' => [
        'max_pages' => 300, 'max_file_mb' => 50, 'dpi' => 250,
        'max_long_edge' => 4000, 'ai_long_edge' => 1568,
        'docx_max_uncompressed_mb' => 20, 'process_timeout' => 120,
        'binaries' => ['pdfinfo' => '/usr/bin/pdfinfo', 'pdftotext' => '/usr/bin/pdftotext',
            'pdftoppm' => '/usr/bin/pdftoppm', 'tesseract' => '/usr/bin/tesseract'],
    ],
    'pdf' => ['chrome_path' => env('DISPLANERY_CHROME_PATH', '/usr/local/bin/chrome-headless-shell')],
    'idle_timeout_minutes' => ['default' => 60, 'platform' => 30],
    'permissions' => ['review_internal_regulation', 'view_all_company_cases'],
    'deadlines' => [
        'response_days' => 3,
        'procedure_days' => 15,
        'appeal_days' => 30,
        'approaching_hours' => 48,
        'appeal_ending_days' => 3,
    ],
];
