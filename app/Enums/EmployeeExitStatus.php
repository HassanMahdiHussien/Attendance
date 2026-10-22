<?php

namespace App\Enums;

enum EmployeeExitStatus: string
{
    case None = 'none';
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
}
