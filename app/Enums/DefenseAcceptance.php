<?php

namespace App\Enums;

enum DefenseAcceptance: string
{
    case AcceptedFully = 'accepted_fully';
    case AcceptedPartially = 'accepted_partially';
    case Rejected = 'rejected';
}
