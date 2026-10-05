<?php

namespace Database\Seeders;

use App\Enums\Jurisdiction;
use App\Enums\PoolRole;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PilotCompanySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $company = Company::create(['name' => 'شركة الواحة للمشروبات الغازية', 'enabled_languages' => ['ar', 'en']]);

            app(TenantContext::class)->runForCompany($company, function () use ($company): void {
                $branch = Branch::create([
                    'company_id' => $company->id,
                    'name' => 'الحلة',
                    'jurisdiction' => Jurisdiction::FederalIraq,
                ]);

                foreach (['خط الإنتاج 1', 'خط الإنتاج 2', 'المخزن', 'البوابة الرئيسية'] as $name) {
                    Location::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => $name]);
                }

                $people = [
                    ['ليث فاضل كريم', 'الإدارة', 'المدير العام', true],
                    ['رنا ياسر جبار', 'الموارد البشرية', 'مديرة الموارد البشرية', false],
                    ['مروان ناظم طه', 'الموارد البشرية', 'مسؤول شؤون الموظفين', false],
                    ['سلمى فؤاد جاسم', 'الموارد البشرية', 'منسقة شؤون الموظفين', false],
                    ['نادر كاظم رؤوف', 'الموارد البشرية', 'مسؤول الرواتب', false],
                    ['دلال حيدر عباس', 'الموارد البشرية', 'مسؤولة التدريب', false],
                    ['يمنى قاسم فلاح', 'الشؤون القانونية', 'مستشارة قانونية', false],
                    ['زياد خليل داود', 'الشؤون القانونية', 'باحث قانوني', false],
                    ['هدى رائد محسن', 'اللجنة النقابية', 'ممثلة العمال', false],
                    ['فراس عادل سمير', 'اللجنة النقابية', 'ممثل العمال', false],
                    ['صفاء وليد محمود', 'الإنتاج', 'مشرف خط الإنتاج', false],
                    ['ميسون ثامر يوسف', 'المخازن', 'أمينة المخزن', false],
                ];

                $employees = [];
                foreach ($people as [$name, $department, $position, $isGeneralManager]) {
                    $employees[] = Employee::create([
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'name' => $name,
                        'department' => $department,
                        'position' => $position,
                        'hire_date' => '2024-01-01',
                        'is_general_manager' => $isGeneralManager,
                    ]);
                }

                $mandate = CommitteeMandate::create([
                    'company_id' => $company->id,
                    'order_number' => '1097',
                    'order_date' => '2026-08-26',
                    'effective_from' => '2026-08-26',
                ]);

                foreach ([[PoolRole::Hr, [1, 2, 3, 4, 5]], [PoolRole::Legal, [6, 7]], [PoolRole::LaborUnion, [8, 9]]] as [$role, $indices]) {
                    foreach ($indices as $index) {
                        CommitteePoolMember::create([
                            'company_id' => $company->id,
                            'mandate_id' => $mandate->id,
                            'branch_id' => null,
                            'role_type' => $role,
                            'person_name' => $employees[$index]->name,
                            'employee_id' => $employees[$index]->id,
                        ]);
                    }
                }
            });
        });
    }
}
