<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('app.seed_default_password');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('SEED_DEFAULT_PASSWORD must be set before seeding local users.');
        }

        $company = Company::where('name', 'شركة الواحة للمشروبات الغازية')->firstOrFail();

        $platformAdmin = new User;
        $platformAdmin->name = 'مدير المنصة';
        $platformAdmin->email = 'platform.admin@example.test';
        $platformAdmin->password = $password;
        $platformAdmin->company_id = null;
        $platformAdmin->primary_role = UserRole::PlatformAdmin;
        $platformAdmin->save();

        $companyAdmin = new User;
        $companyAdmin->name = 'مدير شؤون الموظفين';
        $companyAdmin->email = 'company.admin@example.test';
        $companyAdmin->password = $password;
        $companyAdmin->company_id = $company->id;
        $companyAdmin->primary_role = UserRole::CompanyAdmin;
        $companyAdmin->save();
    }
}
