<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalUsersSeeder extends Seeder
{
    public const DEMO_COMPANY_NAME = 'شركة الواحة للمشروبات الغازية';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeders cannot run in production.');
        }
        $password = config('app.seed_default_password');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('SEED_DEFAULT_PASSWORD must be set before seeding local users.');
        }

        $company = Company::where('name', self::DEMO_COMPANY_NAME)->firstOrFail();

        $platformAdmin = new User;
        $platformAdmin->name = 'Platform Administrator';
        $platformAdmin->email = 'platform.admin@example.test';
        $platformAdmin->password = $password;
        $platformAdmin->company_id = null;
        $platformAdmin->primary_role = UserRole::PlatformAdmin;
        $platformAdmin->save();

        $hrUser = new User;
        $hrUser->name = 'Pilot HR User';
        $hrUser->email = 'hr@example.test';
        $hrUser->password = $password;
        $hrUser->company_id = $company->id;
        $hrUser->primary_role = UserRole::HrStaff;
        $hrUser->save();
    }
}
