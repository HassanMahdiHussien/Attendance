<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Received = 'received';
    case UnderReview = 'under_review';
    case ConvertedToCase = 'converted_to_case';
    case Dismissed = 'dismissed';
}
