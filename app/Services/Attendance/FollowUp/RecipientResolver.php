<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\FollowUpCase;
use App\Models\Employee;

class RecipientResolver
{
    public function forRoute(FollowUpCase $case, array $route): ?array
    {
        $employee = $case->employee;
        $recipientId = $route['recipient_employee_id'] ?? null;
        $recipient = $recipientId === null ? null : Employee::query()->find($recipientId);
        if ($route['kind'] === 'approver') {
            $address = $recipient?->email;
        } else {
            $address = $recipient?->email;
            if (blank($address) || $this->same($address, $employee->email)) {
                $address = $employee->fallback_notification_email;
            }
        }
        if (blank($address) || $this->same($address, $employee->email)) {
            return null;
        }

        return [
            'address' => mb_strtolower(trim($address)),
            'recipient_employee_id' => $recipient?->id,
            'kind' => $route['kind'],
        ];
    }

    private function same(?string $first, ?string $second): bool
    {
        return filled($first) && filled($second)
            && mb_strtolower(trim($first)) === mb_strtolower(trim($second));
    }
}
