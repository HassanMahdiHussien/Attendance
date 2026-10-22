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
        });
    }
}
