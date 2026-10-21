<?php

namespace App\Enums;

enum SequenceKey: string
{
    case CaseNumber = 'case';
    case ReportNumber = 'report';
    case EmployeeCode = 'employee_code';
    case DocumentReference = 'document_reference';
}
