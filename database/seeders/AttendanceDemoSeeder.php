<?php

namespace Database\Seeders;

use App\Enums\Jurisdiction;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\DeviceRegistry;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveBalanceImport;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeders cannot run in production.');
        }

        $company = Company::query()->where('name', LocalUsersSeeder::DEMO_COMPANY_NAME)->firstOrFail();
        $actor = User::query()->where('email', 'hr@example.test')->firstOrFail();
        $queue = Queue::getFacadeRoot();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        try {
            app(TenantContext::class)->runForCompany($company, function () use ($company, $actor): void {
                auth()->login($actor);
                try {
                    $this->seedCompany($company, $actor);
                } finally {
                    auth()->logout();
                }
            });
        } finally {
            Queue::swap($queue);
        }
    }

    private function seedCompany(Company $company, User $actor): void
    {
        $end = CarbonImmutable::now('Asia/Baghdad')->subDay()->startOfDay();
        $start = $end->subDays(app()->runningUnitTests() ? 11 : 41);
        $branches = Branch::query()->where('company_id', $company->id)->get()->keyBy('name');
        foreach (['كربلاء', 'أربيل', 'بغداد'] as $name) {
            $branches[$name] = Branch::create([
                'company_id' => $company->id, 'name' => $name,
                'jurisdiction' => $name === 'أربيل' ? Jurisdiction::KurdistanRegion : Jurisdiction::FederalIraq,
            ]);
        }

        $history = app(EmployeeHistory::class);
        $employees = [];
        foreach (Employee::query()->where('company_id', $company->id)->orderBy('id')->get() as $index => $employee) {
            $employee->employee_code = sprintf('2300%04d', $index + 101);
            $employee->save();
            $history->record($employee, [
                'work_pattern' => $index >= 10 ? 'shift' : 'office',
                'schedule_start' => '08:00:00', 'schedule_end' => $index >= 10 ? '16:00:00' : '17:00:00',
                'rest_days' => $index >= 10 ? [5] : [5, 6],
            ], '2024-01-01', 'initial');
            $employees[] = $employee->fresh();
        }

        $givenAr = ['رائد', 'بسمة', 'مازن', 'هالة', 'وائل', 'ميس', 'ساهر', 'لين', 'جود', 'ياسر'];
        $givenEn = ['Raed', 'Basma', 'Mazin', 'Hala', 'Wael', 'Mays', 'Sahir', 'Leen', 'Joud', 'Yaser'];
        $branchNames = ['الحلة', 'كربلاء', 'أربيل', 'بغداد'];
        for ($index = 0; $index < 40; $index++) {
            $shift = $index % 3 === 0;
            $night = $shift && $index === 0;
            $branch = $branches[$branchNames[$index % 4]];
            $values = [
                'company_id' => $company->id, 'branch_id' => $branch->id,
                'employee_code' => sprintf('2300%04d', $index + 113),
                'name' => $givenAr[$index % 10].' بن خيال '.($index + 1),
                'name_en' => $givenEn[$index % 10].' Fictional '.($index + 1),
                'department' => $shift ? 'وحدة التشغيل الخيالية' : 'مكتب التجربة الخيالي',
                'department_en' => $shift ? 'Imaginary Operations' : 'Fictional Office',
                'position' => $shift ? 'مشغل تجريبي' : 'موظف تجريبي',
                'position_en' => $shift ? 'Demo Operator' : 'Demo Associate',
                'gender' => 'unspecified', 'hire_date' => '2024-01-01',
                'work_pattern' => $shift ? 'shift' : 'office',
                'schedule_start' => $night ? '16:00:00' : '08:00:00',
                'schedule_end' => $night ? '00:00:00' : ($shift ? '16:00:00' : '17:00:00'),
                'rest_days' => $shift ? [5] : [5, 6],
                'staff_category' => $shift ? 'B' : 'W',
            ];
            $employees[] = $history->create(
                array_intersect_key($values, array_flip(['company_id', 'branch_id', 'employee_code', 'name', 'gender', 'hire_date'])),
                $values, '2024-01-01', 'initial', $actor,
            );
        }

        $devices = app(DeviceRegistry::class);
        foreach ($branches as $branch) {
            foreach (['In' => 'check_in', 'Out' => 'check_out'] as $suffix => $role) {
                $serial = 'DEMO-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT).'-'.$suffix;
                $device = $devices->register($company, $serial, $branch->name.'_'.$suffix.'_Face', $suffix === 'In' ? 'Check In' : 'Check Out');
                $devices->approve($device, $role, $branch->id, $actor, $start->toDateString(), 'Demo device');
            }
        }

        $holidays = app(Holidays::class);
        foreach ([['Demo Foundation Day', 'يوم التأسيس التجريبي', 28], ['Demo Community Day', 'يوم المجتمع التجريبي', 14]] as [$english, $arabic, $offset]) {
            $date = $this->officeDayBefore($end, $offset)->toDateString();
            $holiday = $holidays->create($company, [
                'name_en' => $english, 'name_ar' => $arabic,
                'ranges' => [['from' => $date, 'to' => $date]],
            ], $actor);
            $holidays->approve($holiday, [], $actor, 'Approved for the fictional demo calendar');
        }

        $this->importBalances($company, $actor, $employees, $end);
        $this->importLeave($company, $actor, $employees, $end);
        $this->importTransactions($company, $actor, $employees, $branches, $start, $end);
    }

    private function importBalances(Company $company, User $actor, array $employees, CarbonImmutable $end): void
    {
        $rows = [];
        foreach ($employees as $index => $employee) {
            $rows[] = [$employee->employee_code, (string) (12 + $index % 15)];
        }
        $file = $this->csv('Demo Annual Leave Balance.csv',
            ['Pers. No.', 'Total Remaining Annual Leave Days'], $rows);
        try {
            $reader = app(LeaveBalanceFile::class);
            $sheet = $reader->inspect($file);
            $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);
            $import = app(LeaveBalanceImport::class);
            $date = $end->toDateString();
            $year = (int) $end->year;
            $preview = $import->preview($company, $file, $mapping, $date, $year);
            $batch = $import->commit($company, $file, $actor, $mapping, $date, $year, $preview['token']);
            app()->call([new ImportLeaveBalanceJob($batch->id), 'handle']);
        } finally {
            unlink($file->getRealPath());
        }
    }

    private function importLeave(Company $company, User $actor, array $employees, CarbonImmutable $end): void
    {
        $rows = [];
        $examples = [
            [0, 9, 'Approved', 'Annual Leave', '1', ''],
            [1, 8, 'Approved', 'Sick Leave', '1', ''],
            [2, 7, 'Approved', 'Administrative Leave', '0.5', ''],
            [3, 6, 'Manager Approval', 'Annual Leave', '1', ''],
            [4, 5, 'Approved', 'Annual Leave', '1', ''],
            [5, 4, 'HR Approval', 'Annual Leave', '0.5', ''],
            [6, 3, 'Approved', 'Annual Leave', '1', ''],
            [7, 2, 'Approved', 'Annual Leave', '1', ''],
        ];
        foreach ($examples as $number => [$person, $offset, $status, $type, $duration, $rejected]) {
            $date = $this->officeDayBefore($end, $offset)->toDateString();
            $rows[] = ['DEMO-LEAVE-'.($number + 1), $employees[$person]->employee_code,
                $employees[$person]->name_en, 'Demo Approver', 'Demo Site', 'Demo Department',
                $status, $type, '', $date, $date, $duration, '', 'Demo HR', $date,
                'Demo Manager', $rejected];
        }
        $rejectedDate = $this->officeDayBefore($end, 10)->toDateString();
        $rows[] = ['DEMO-LEAVE-9', $employees[8]->employee_code, $employees[8]->name_en,
            'Demo Approver', 'Demo Site', 'Demo Department', 'Manager Approval', 'Annual Leave', '',
            $rejectedDate, $rejectedDate, '1', '', 'Demo HR', $rejectedDate, 'Demo Manager', $rejectedDate];
        $file = $this->csv('IRAQ All Leave REPORT-'.$end->format('Y-m-d').'-23-55-00.csv', LeaveFile::HEADERS, $rows);
        try {
            $reader = app(LeaveFile::class);
            $mapping = $reader->suggest($reader->inspect($file)['headers']);
            $import = app(LeaveImport::class);
            $scope = ['scope' => 'partial', 'snapshot_at' => $end->format('Y-m-d').' 23:55:00'];
            $preview = $import->preview($company, $file, $mapping, $scope);
            $batch = $import->commit($company, $file, $actor, $mapping, $scope, $preview['token']);
            app()->call([new ImportLeaveJob($batch->id), 'handle']);
        } finally {
            unlink($file->getRealPath());
        }
    }

    private function importTransactions(Company $company, User $actor, array $employees, $branches, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $rows = [];
        $serials = [];
        foreach ($branches as $branch) {
            $serials[$branch->id] = ['DEMO-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT).'-In',
                'DEMO-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT).'-Out'];
        }
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            foreach ($employees as $index => $employee) {
                $offset = $start->diffInDays($day);
                $rest = $employee->work_pattern?->value === 'shift' ? [5] : [5, 6];
                if (in_array($day->dayOfWeekIso, $rest, true) && ! ($index === 0 && $offset === 15)) {
                    continue;
                }
                if ($day->isSameDay($this->officeDayBefore($end, 3)) && $employee->branch->name === 'كربلاء') {
                    continue;
                }
                if (($index + $offset) % 43 === 0
                    || ($index < 2 && $day->isSameDay($this->officeDayBefore($end, 9)))
                    || ($index === 2 && $day->isSameDay($this->officeDayBefore($end, 3)))) {
                    continue;
                }
                $shift = $employee->work_pattern?->value === 'shift';
                $night = $shift && $index === 12;
                $in = $night ? '16:04' : ($shift ? '08:03' : match (($index * 7 + $offset * 3) % 9) {
                    0 => '09:17', 1 => '08:54', default => '08:18',
                });
                $out = $night ? '00:20' : ($shift ? '16:12' : (($index + $offset) % 11 === 0 ? '19:10' : '17:22'));
                $branch = $employee->branch;
                [$inSerial, $outSerial] = $serials[$branch->id];
                $rows[] = [$employee->employee_code, $employee->name_en, $employee->department_en,
                    $day->toDateString(), $in, 'Check In', $inSerial, $branch->name.'_In_Face', 'Demo'];
                if (($index + $offset) % 13 === 0) {
                    $rows[] = end($rows);
                }
                if (($index + $offset) % 31 !== 0) {
                    $outDate = $night ? $day->addDay()->toDateString() : $day->toDateString();
                    $rows[] = [$employee->employee_code, $employee->name_en, $employee->department_en,
                        $outDate, $out, 'Check Out', $outSerial, $branch->name.'_Out_Face', 'Demo'];
                }
            }
        }
        $headers = ['Employee ID', 'First Name', 'Department', 'Date', 'Time', 'Punch State', 'Serial Number', 'Device Name', 'Data Sources'];
        $file = $this->csv('Transaction_'.$end->format('Ymd').'235959.csv', $headers, $rows);
        try {
            $reader = app(TransactionFile::class);
            $mapping = $reader->suggest($reader->inspect($file)['headers']);
            $import = app(TransactionImport::class);
            $coverage = ['branch_ids' => $branches->pluck('id')->all(),
                'from' => $start->toDateString().' 00:00:00',
                'to' => $end->toDateString().' 23:59:59'];
            $preview = $import->preview($company, $file, $mapping, $coverage);
            $batch = $import->commit($company, $file, $actor, $mapping, $coverage, $preview['token']);
            app()->call([new ImportTransactionsJob($batch->id), 'handle']);
            app(AttendanceProcessor::class)->run($company, collect($employees)->pluck('employee_code')->all(),
                $start->toDateString().' 00:00:00', $end->addDay()->toDateString().' 23:59:59', 'import', $batch->id);
            $ids = collect($employees)->pluck('id')->all();
            app(Reconciler::class)->run($company, $ids, 'import');
            app(DayAssessor::class)->run($company, [
                'employee_ids' => $ids, 'from' => $start->toDateString(), 'to' => $end->toDateString(),
            ], 'import');
            app(Reconciler::class)->run($company, $ids, 'days');
        } finally {
            unlink($file->getRealPath());
        }
    }

    private function officeDayBefore(CarbonImmutable $end, int $days): CarbonImmutable
    {
        $date = $end->subDays($days);
        while ($date->dayOfWeekIso >= 5) {
            $date = $date->subDay();
        }

        return $date;
    }

    private function csv(string $name, array $headers, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'demo-seed-');
        $stream = fopen($path, 'wb');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        fclose($stream);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
