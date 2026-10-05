<?php

return [
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
