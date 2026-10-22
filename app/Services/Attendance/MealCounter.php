<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Person;
use App\Models\Company;
use Illuminate\Support\Collection;

class MealCounter
{
    public function rows(Company $company, Person $person, Collection $transactions, DeviceRoles $roles, int $runId): array
    {
        $days = [];
        foreach ($transactions as $transaction) {
            if ($roles->at($transaction) !== 'restaurant') {
                continue;
            }
            $day = $transaction->punched_at->toDateString();
            if (! isset($days[$day])) {
                $days[$day] = [
                    'company_id' => $company->id, 'person_code' => $person->person_code,
                    'employee_id' => $person->employee_id, 'department_label' => $person->department_label,
                    'meal_date' => $day, 'punch_count' => 0, 'repeat_count' => 0,
                    'first_transaction_id' => $transaction->id, 'run_id' => $runId, 'created_at' => now(),
                ];
            }
            $days[$day]['punch_count']++;
            $days[$day]['repeat_count'] = $days[$day]['punch_count'] - 1;
        }

        return array_values($days);
    }
}
