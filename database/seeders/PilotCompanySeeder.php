<?php

namespace Database\Seeders;

use App\Enums\Jurisdiction;
use App\Models\Branch;
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
        if (app()->isProduction()) {
            throw new \RuntimeException('Demo seeders cannot run in production.');
        }
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
                    ['ليث فاضل كريم', 'الإدارة', 'المدير العام'],
                    ['رنا ياسر جبار', 'الموارد البشرية', 'مديرة الموارد البشرية'],
                    ['مروان ناظم طه', 'الموارد البشرية', 'مسؤول شؤون الموظفين'],
                    ['سلمى فؤاد جاسم', 'الموارد البشرية', 'منسقة شؤون الموظفين'],
                    ['نادر كاظم رؤوف', 'الموارد البشرية', 'مسؤول الرواتب'],
                    ['دلال حيدر عباس', 'الموارد البشرية', 'مسؤولة التدريب'],
                    ['يمنى قاسم فلاح', 'الشؤون القانونية', 'مستشارة قانونية'],
                    ['زياد خليل داود', 'الشؤون القانونية', 'باحث قانوني'],
                    ['هدى رائد محسن', 'اللجنة النقابية', 'ممثلة العمال'],
                    ['فراس عادل سمير', 'اللجنة النقابية', 'ممثل العمال'],
                    ['صفاء وليد محمود', 'الإنتاج', 'مشرف خط الإنتاج'],
                    ['ميسون ثامر يوسف', 'المخازن', 'أمينة المخزن'],
                ];

                $englishNames = ['Laith Fadil Karim', 'Rana Yaser Jabbar', 'Marwan Nazim Taha', 'Salma Fuad Jasim', 'Nadir Kazim Raouf', 'Dalal Haider Abbas', 'Yumna Qasim Falah', 'Ziyad Khalil Dawood', 'Huda Raed Mohsen', 'Firas Adil Samir', 'Safaa Walid Mahmoud', 'Maysoun Thamir Youssef'];
                foreach ($people as $index => [$name, $department, $position]) {
                    Employee::create([
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'name' => $name,
                        'name_en' => $englishNames[$index],
                        'department' => $department,
                        'department_en' => match ($index) {
                            0 => 'Administration', 6, 7 => 'Legal Affairs', 8, 9 => 'Labour Union', 10 => 'Production', 11 => 'Warehouse', default => 'Human Resources'
                        },
                        'position' => $position,
                        'position_en' => match ($index) {
                            0 => 'General Manager', 8, 9 => 'Labour Union Representative', 10 => 'Production Supervisor', 11 => 'Warehouse Clerk', default => 'Staff Member'
                        },
                        'gender' => in_array($index, [1, 3, 5, 6, 8, 11], true) ? 'female' : 'male',
                        'hire_date' => '2024-01-01',
                    ]);
                }
            });
        });
    }
}
