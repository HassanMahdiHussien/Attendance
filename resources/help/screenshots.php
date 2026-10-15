<?php

// Help Center screenshots, captured from the seeded demo company by `php artisan help:screenshots`.
// Paths with a record id (/cases/1, /employees/1) assume a freshly seeded demo database.
return [
    'home' => ['path' => '/home', 'user' => 'company.admin@example.test'],
    'submit-report' => ['path' => '/reports/new', 'user' => 'reporter@example.test'],
    'my-reports' => ['path' => '/reports', 'user' => 'reporter@example.test'],
    'reports-inbox' => ['path' => '/reports/inbox', 'user' => 'company.admin@example.test'],
    'new-case' => ['path' => '/cases/new', 'user' => 'company.admin@example.test'],
    'case-board' => ['path' => '/cases', 'user' => 'company.admin@example.test'],
    'case-page' => ['path' => '/cases/1', 'user' => 'company.admin@example.test'],
    'employee-page' => ['path' => '/employees/1', 'user' => 'company.admin@example.test'],
    'dashboard' => ['path' => '/dashboard', 'user' => 'company.admin@example.test'],
    'statistics' => ['path' => '/statistics', 'user' => 'company.admin@example.test'],
];
