<?php

namespace App\Enums;

enum EmailDraftStatus: string
{
    case Received = 'received';
    case Converted = 'converted';
    case Linked = 'linked';
    case Dismissed = 'dismissed';
}
