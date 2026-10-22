<?php

namespace App\Events;

use App\Models\Employee;
use Carbon\CarbonImmutable;

class EmployeeVersionChanged
{
    public function __construct(public Employee $employee, public CarbonImmutable $effectiveFrom) {}
}
