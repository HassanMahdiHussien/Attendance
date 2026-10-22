<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class EmployeeExitChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public Employee $employee, public string $from) {}
}
