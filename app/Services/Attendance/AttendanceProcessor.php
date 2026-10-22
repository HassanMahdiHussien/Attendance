<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Person;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Transaction;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AttendanceProcessor
{
    public const POLICY_VERSION = 1;

    public function __construct(
        private PunchCleanup $cleanup,
        private MealCounter $meals,
        private DeviceRoles $roles,
        private AuditLogger $audit,
    ) {}

    public function run(Company $company, array $codes, string $from, string $to, string $trigger, ?int $batchId = null, ?int $deviceId = null): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes, SORT_STRING);
        $scopeFrom = CarbonImmutable::parse($from, 'Asia/Baghdad');
        $scopeTo = CarbonImmutable::parse($to, 'Asia/Baghdad');
        if ($scopeFrom > $scopeTo) {
            throw new InvalidArgumentException('The attendance processing scope is reversed.');
        }
        $runs = [];
        foreach (['cleanup', 'meals'] as $kind) {
            $runs[$kind] = ProcessingRun::create([
                'company_id' => $company->id, 'kind' => $kind, 'trigger_source' => $trigger,
                'batch_id' => $batchId, 'device_id' => $deviceId, 'person_codes' => $codes,
                'scope_from' => $scopeFrom->toDateTimeString(), 'scope_to' => $scopeTo->toDateTimeString(),
                'policy_version' => self::POLICY_VERSION, 'status' => 'processing', 'started_at' => now(),
            ]);
        }
        try {
            DB::transaction(function () use ($company, $codes, $scopeFrom, $scopeTo, $runs): void {
                // Sorted row locks make overlapping scopes serialize for every shared person.
                $people = Person::query()->where('company_id', $company->id)->whereIn('person_code', $codes)
                    ->orderBy('person_code')->lockForUpdate()->get();
                $gap = (int) config('attendance.cleanup.max_group_gap_hours', 16);
                $start = $scopeFrom->subHours($gap)->startOfDay();
                $end = $scopeTo->addHours($gap)->endOfDay();
                $readStart = $start->subHours($gap);
                $readEnd = $end->addHours($gap);
                $punchCount = 0;
                $mealCount = 0;
                foreach ($people as $person) {
                    $transactions = Transaction::query()->with('device')->where('company_id', $company->id)
                        ->where('person_code', $person->person_code)
                        ->whereBetween('punched_at', [$readStart->toDateTimeString(), $readEnd->toDateTimeString()])
                        ->orderBy('punched_at')->orderBy('id')->get();
                    $this->roles->load($company, $transactions);
                    $target = $transactions->filter(fn (Transaction $transaction): bool => $transaction->punched_at >= $start && $transaction->punched_at <= $end);
                    $transactionIds = $target->pluck('id')->all();
                    if ($transactionIds !== []) {
                        DB::table('attendance_punches')->where('company_id', $company->id)
                            ->whereIn('transaction_id', $transactionIds)->whereNull('superseded_by_run_id')
                            ->update(['superseded_by_run_id' => $runs['cleanup']->id]);
                    }
                    $targetIds = array_fill_keys($transactionIds, true);
                    $punchRows = array_values(array_filter(
                        $this->cleanup->rows($company, $person, $transactions, $this->roles, $runs['cleanup']->id),
                        fn (array $row): bool => isset($targetIds[$row['transaction_id']]),
                    ));
                    foreach (array_chunk($punchRows, 500) as $chunk) {
                        DB::table('attendance_punches')->insert($chunk);
                    }
                    $punchCount += count($punchRows);

                    DB::table('attendance_meals')->where('company_id', $company->id)
                        ->where('person_code', $person->person_code)
                        ->whereBetween('meal_date', [$start->toDateString(), $end->toDateString()])
                        ->whereNull('superseded_by_run_id')
                        ->update(['superseded_by_run_id' => $runs['meals']->id]);
                    $mealRows = array_values(array_filter(
                        $this->meals->rows($company, $person, $transactions, $this->roles, $runs['meals']->id),
                        fn (array $row): bool => $row['meal_date'] >= $start->toDateString() && $row['meal_date'] <= $end->toDateString(),
                    ));
                    foreach (array_chunk($mealRows, 500) as $chunk) {
                        DB::table('attendance_meals')->insert($chunk);
                    }
                    $mealCount += count($mealRows);
                }
                $counts = ['cleanup' => ['punches' => $punchCount], 'meals' => ['days' => $mealCount]];
                foreach ($runs as $kind => $run) {
                    $run->update(['status' => 'completed', 'counts' => $counts[$kind], 'finished_at' => now()]);
                    $this->audit->log('attendance.processing.completed', $run, [
                        'kind' => $kind, 'trigger' => $run->trigger_source, 'run_id' => $run->id,
                        'count' => $counts[$kind], 'policy_version' => self::POLICY_VERSION,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            foreach ($runs as $run) {
                $run->update(['status' => 'failed', 'finished_at' => now()]);
                $this->audit->log('attendance.processing.failed', $run, [
                    'kind' => $run->kind, 'trigger' => $run->trigger_source, 'run_id' => $run->id,
                ]);
            }

            throw $exception;
        }

        return [$runs['cleanup']->fresh(), $runs['meals']->fresh()];
    }
}
