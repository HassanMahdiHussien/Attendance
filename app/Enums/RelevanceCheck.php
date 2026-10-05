<?php

namespace App\Enums;

enum RelevanceCheck: string
{
    case Supports = 'supports';
    case Partial = 'partial';
    case DoesNotSupport = 'does_not_support';
}
