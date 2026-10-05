<?php

namespace App\Enums;

enum Vote: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Abstain = 'abstain';
}
