<?php

namespace App\Enums;

enum AiGenerationStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case RejectedFormat = 'rejected_format';
}
