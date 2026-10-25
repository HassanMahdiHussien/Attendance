<?php

namespace App\Services\Attendance\Reporting;

class ExportKinds
{
    public const ALL = [
        'employee_statement_xlsx', 'lateness_reconciliation', 'absence', 'missing_punches',
        'leave_and_credit', 'restaurant_usage', 'data_issues', 'overtime', 'compensatory_days',
        'follow_up_cases', 'statement_pdf',
    ];

    public const EMPLOYEE_ONLY = ['employee_statement_xlsx', 'statement_pdf'];
}
