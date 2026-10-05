<?php

namespace App\Enums;

enum SequenceKey: string
{
    case CaseNumber = 'case';
    case EmployeeCode = 'employee_code';
    case DocumentReference = 'document_reference';
}
