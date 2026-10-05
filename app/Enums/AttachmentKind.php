<?php

namespace App\Enums;

enum AttachmentKind: string
{
    case ReportEvidence = 'report_evidence';
    case EmployeeResponseScan = 'employee_response_scan';
    case SignedDocumentScan = 'signed_document_scan';
    case DeliveryProof = 'delivery_proof';
    case RegulationSource = 'regulation_source';
    case Other = 'other';
}
