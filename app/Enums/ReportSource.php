<?php

namespace App\Enums;

enum ReportSource: string
{
    case Reporter = 'reporter';
    case EmailForward = 'email_forward';
}
