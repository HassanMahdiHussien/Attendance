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
use Illuminate\Support\Facades\Storage;

class PilotCompanySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Demo seeders cannot run in production.');
        }
        DB::transaction(function (): void {
            $company = Company::create(['name' => 'شركة الواحة للمشروبات الغازية', 'enabled_languages' => ['ar', 'en']]);

            $company->update(['internal_regulation_answer' => 'no', 'internal_regulation_answered_at' => now()]);
            $logo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
            $path = "letterheads/{$company->id}/seed.png";
            Storage::disk('local')->put($path, $logo);
            foreach (['ar', 'en'] as $language) {
                DB::table('company_letterheads')->insert([
                    'company_id' => $company->id, 'language_code' => $language, 'version' => 2,
                    'name' => $company->name, 'footer_text' => 'CONFIDENTIAL',
                    'header_mode' => 'logo_text', 'logo_path' => $path,
                    'logo_sha256' => hash('sha256', $logo), 'needs_review' => false, 'created_at' => now(),
                ]);
            }

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
                $englishNames = ['Laith Fadil Karim', 'Rana Yaser Jabbar', 'Marwan Nazim Taha', 'Salma Fuad Jasim', 'Nadir Kazim Raouf', 'Dalal Haider Abbas', 'Yumna Qasim Falah', 'Ziyad Khalil Dawood', 'Huda Raed Mohsen', 'Firas Adil Samir', 'Safaa Walid Mahmoud', 'Maysoun Thamir Youssef'];
                foreach ($people as $index => [$name, $department, $position, $isGeneralManager]) {
                    $employees[] = Employee::create([
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
                            'person_name_en' => $employees[$index]->name_en,
                            'employee_id' => $employees[$index]->id,
                        ]);
                    }
                }
            });
        });
    }
}
