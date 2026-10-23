<?php

use App\Exceptions\TenantMismatch;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('shift policy defaults and nullable day fields preserve existing office data', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $policy = Policy::factory()->create();
    $day = Day::factory()->create();

    expect((int) $policy->fresh()->absence_minutes)->toBe(480)
        ->and((int) $policy->fresh()->min_presence_minutes)->toBe(480)
        ->and($policy->fresh()->shift_starts)->toBe(['00:00', '08:00', '16:00'])
        ->and($policy->fresh()->shift_rest_days)->toBe([5])
        ->and($day->fresh()->shift_start)->toBeNull()
        ->and($day->fresh()->shift_source)->toBeNull()
        ->and($day->fresh()->shift_evidence)->toBeNull();
});

test('shift decisions remain tenant scoped and append only', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $other = $context->runForCompany($b, fn () => DayDecision::factory()->create([
        'decision' => 'pick_shift', 'shift_start' => '16:00:00',
    ]));
    $own = $context->runForCompany($a, fn () => DayDecision::factory()->create([
        'decision' => 'pick_shift', 'shift_start' => '08:00:00',
    ]));

    $context->setCompany($a);
    expect(DayDecision::find($other->id))->toBeNull()
        ->and(DayDecision::find($own->id)?->shift_start)->toBe('08:00:00')
        ->and(fn () => DayDecision::factory()->create([
            'company_id' => $b->id, 'decision' => 'pick_shift', 'shift_start' => '00:00:00',
        ]))->toThrow(TenantMismatch::class);
    foreach (['UPDATE attendance_day_decisions SET shift_start = shift_start WHERE id = ?', 'DELETE FROM attendance_day_decisions WHERE id = ?'] as $sql) {
        try {
            DB::statement($sql, [$own->id]);
            $this->fail('Append-only trigger accepted a change to a shift decision.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('IMMUTABLE:');
        }
    }
});
