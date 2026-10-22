<?php

namespace App\Enums;

enum EmployeeWorkPattern: string
{
    case Office = 'office';
    case Shift = 'shift';
    case Security = 'security';
}
