<?php

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
