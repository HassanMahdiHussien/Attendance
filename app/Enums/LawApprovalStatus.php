<?php

namespace App\Enums;

enum LawApprovalStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
}
