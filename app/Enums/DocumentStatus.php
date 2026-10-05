<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case Issued = 'issued';
    case Superseded = 'superseded';
}
