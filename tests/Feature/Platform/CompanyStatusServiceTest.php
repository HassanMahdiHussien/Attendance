<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;
use Illuminate\Support\Facades\DB;

test('company deactivation purges only its sessions and audits each status change', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $actor = User::factory()->create();
    $first = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $second = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $outside = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'hr_staff']);
    foreach (['first' => $first, 'second' => $second, 'outside' => $outside] as $id => $user) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    }

    $service = app(CompanyStatusService::class);
    $service->setActive($company, false, $actor);
    expect($company->is_active)->toBeFalse();
    expect(DB::table('sessions')->pluck('id')->all())->toBe(['outside']);
    $deactivated = AuditLog::where('action', 'company.deactivated')->sole();
    expect($deactivated->actor_user_id)->toBe($actor->id);
    expect($deactivated->company_id)->toBe($company->id);
    expect($deactivated->metadata['sessions_purged'])->toBe(2);
    expect($deactivated->metadata['before']['is_active'])->toBeTrue();
    expect($deactivated->metadata['after']['is_active'])->toBeFalse();

    DB::table('sessions')->insert(['id' => 'new-company-session', 'user_id' => $first->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    $service->setActive($company, true, $actor);
    expect($company->is_active)->toBeTrue();
    expect(DB::table('sessions')->where('id', 'new-company-session')->exists())->toBeTrue();
    expect(DB::table('sessions')->where('id', 'outside')->exists())->toBeTrue();
    $activated = AuditLog::where('action', 'company.activated')->sole();
    expect($activated->metadata['sessions_purged'])->toBe(0);
});
