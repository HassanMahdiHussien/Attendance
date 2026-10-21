<?php

return [
    'backups' => ['enabled' => env('BACKUPS_ENABLED', false), 'notify_email' => env('BACKUP_NOTIFY_EMAIL')],
    'restore_drill' => ['connection' => 'restore_drill', 'database' => env('DB_DRILL_DATABASE') ?: env('DB_DATABASE', 'laravel').'_restore_drill'],
    'healthchecks' => [
        'heartbeat' => env('HEALTHCHECK_HEARTBEAT_URL'),
        'backup' => env('HEALTHCHECK_BACKUP_URL'),
        'restore_drill' => env('HEALTHCHECK_RESTORE_DRILL_URL'),
        'queue_failures' => env('HEALTHCHECK_QUEUE_FAILURES_URL'),
    ],
    'pdf' => ['chrome_path' => env('DISPLANERY_CHROME_PATH', '/usr/local/bin/chrome-headless-shell')],
    'idle_timeout_minutes' => ['default' => 60, 'platform' => 30],
];
