<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Person;
use App\Models\Attendance\Transaction;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PunchCleanup
{
    public function rows(Company $company, Person $person, Collection $transactions, DeviceRoles $roles, int $runId): array
    {
        $directions = $this->singleDeviceDirections($transactions, $roles);
        $rows = [];
        $group = [];
        $firstAt = null;
        $lastRole = null;
        $gap = (int) config('attendance.cleanup.max_group_gap_hours', 16);
        $finish = function () use (&$rows, &$group): void {
            if ($group === []) {
                return;
            }
            $lastIndex = count($group) - 1;
            foreach ($group as $index => $entry) {
                $used = $entry['role'] === 'check_in' ? $index === 0 : $index === $lastIndex;
                $entry['status'] = $used ? 'used' : 'repeat';
                $entry['reason_code'] = $used ? null : ($entry['role'] === 'check_in' ? 'repeated_check_in' : 'repeated_check_out');
                $rows[] = $entry;
            }
            $group = [];
        };
        foreach ($transactions as $transaction) {
            $role = $roles->at($transaction);
            if ($role === 'restaurant' || ($transaction->punch_state === '255' && $role !== 'check_in_out')) {
                continue;
            }
            $unclear = $role === 'check_in_out' && ($directions[$transaction->id] ?? null) === 'unclear_direction';
            if ($role === 'check_in_out') {
                $role = $unclear ? null : ($directions[$transaction->id] ?? null);
            }
            $entry = [
                'company_id' => $company->id, 'transaction_id' => $transaction->id, 'run_id' => $runId,
                'person_code' => $person->person_code, 'employee_id' => $person->employee_id,
                'punched_at' => $transaction->punched_at->toDateTimeString(),
                'role' => in_array($role, ['check_in', 'check_out'], true) ? $role : null,
                'status' => 'used', 'group_key' => null, 'reason_code' => null, 'created_at' => now(),
            ];
            if ($unclear) {
                $finish();
                $entry['status'] = 'repeat';
                $entry['reason_code'] = 'unclear_direction';
                $rows[] = $entry;

                continue;
            }
            if ($role === null || ! in_array($role, ['check_in', 'check_out'], true)) {
                $entry['status'] = 'unused_device';
                $entry['reason_code'] = $role === 'retired' ? 'device_retired' : 'device_not_approved';
                $rows[] = $entry;

                continue;
            }
            if ($person->kind !== 'employee') {
                $entry['status'] = 'non_employee';
                $entry['reason_code'] = 'person_not_employee';
                $rows[] = $entry;

                continue;
            }
            $at = CarbonImmutable::parse($transaction->punched_at);
            if ($group !== [] && ($role !== $lastRole || $firstAt->diffInSeconds($at) > $gap * 3600)) {
                $finish();
            }
            if ($group === []) {
                $firstAt = $at;
                $lastRole = $role;
            }
            $entry['group_key'] = hash('sha256', json_encode([
                $person->person_code, $group === [] ? $transaction->id : $group[0]['transaction_id'],
            ], JSON_THROW_ON_ERROR));
            $group[] = $entry;
        }
        $finish();

        return $rows;
    }

    private function singleDeviceDirections(Collection $transactions, DeviceRoles $roles): array
    {
        $byDay = [];
        foreach ($transactions as $transaction) {
            if ($roles->at($transaction) === 'check_in_out') {
                $byDay[$transaction->punched_at->toDateString()][] = $transaction;
            }
        }
        $directions = [];
        $mergeSeconds = (int) config('attendance.cleanup.single_device_merge_minutes', 15) * 60;
        foreach ($byDay as $transactionsForDay) {
            usort($transactionsForDay, fn (Transaction $a, Transaction $b): int => [$a->punched_at, $a->id] <=> [$b->punched_at, $b->id]);
            $groups = [];
            $previous = null;
            foreach ($transactionsForDay as $transaction) {
                if ($previous === null || $previous->punched_at->diffInSeconds($transaction->punched_at) > $mergeSeconds) {
                    $groups[] = [];
                }
                $groups[array_key_last($groups)][] = $transaction;
                $previous = $transaction;
            }
            $count = count($groups);
            foreach ($groups as $index => $group) {
                $direction = match (true) {
                    $count === 1 => $group[0]->punched_at->format('H:i:s') < '12:00:00' ? 'check_in' : 'check_out',
                    $count % 2 === 0 => $index % 2 === 0 ? 'check_in' : 'check_out',
                    $index === 0 => 'check_in',
                    $index === $count - 1 => 'check_out',
                    default => 'unclear_direction',
                };
                foreach ($group as $transaction) {
                    $directions[$transaction->id] = $direction;
                }
            }
        }

        return $directions;
    }
}
