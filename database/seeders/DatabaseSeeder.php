<?php

namespace Database\Seeders;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runAcrossAllCompanies(function (): void {
            DB::transaction(function (): void {
                $this->call([
                    PilotCompanySeeder::class,
                    LocalUsersSeeder::class,
                ]);
            });
            // The foundation seed test checks the original 12-person pilot separately.
            if (! app()->runningUnitTests() || config('attendance.seed_demo_in_tests', false)) {
                $this->call(AttendanceDemoSeeder::class);
            }
        });
    }
}
