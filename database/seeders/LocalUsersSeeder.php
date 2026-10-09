<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeders cannot run in production.');
        }
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

        foreach (['legal', 'hr', 'labor_union'] as $role) {
            $member = CommitteePoolMember::query()->where('company_id', $company->id)
                ->where('role_type', $role)->firstOrFail();
            $approver = new User;
            $approver->name = $member->person_name;
            $approver->email = "committee.{$role}@example.test";
            $approver->password = $password;
            $approver->company_id = $company->id;
            $approver->primary_role = UserRole::CommitteeMember;
            $approver->employee_id = $member->employee_id;
            $approver->save();
            $member->user_id = $approver->id;
            $member->save();
        }
    }
}
