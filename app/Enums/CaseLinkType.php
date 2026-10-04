<?php

namespace App\Enums;

enum CaseLinkType: string
{
    case PriorRecord = 'prior_record';
    case Related = 'related';
    case DuplicateMerge = 'duplicate_merge';
}
