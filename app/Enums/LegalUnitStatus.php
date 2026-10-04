<?php

namespace App\Enums;

enum LegalUnitStatus: string
{
    case Unverified = 'unverified';
    case Mismatch = 'mismatch';
    case VerifiedAuto = 'verified_auto';
    case VerifiedHuman = 'verified_human';
}
