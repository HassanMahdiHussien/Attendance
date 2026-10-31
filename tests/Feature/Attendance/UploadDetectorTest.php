<?php

use App\Enums\UserRole;
use App\Models\Attendance\ImportMapping;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\UploadDetector;
use App\Services\Leave\LeaveFile;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $branch = Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Hilla']);
    Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'employee_code' => '12345678']);
    $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('the four report layouts and an unknown file are classified by the existing inspectors', function (): void {
    $attendance = UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv',
        "Employee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n");
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, LeaveFile::HEADERS);
    fputcsv($stream, ['FORM-1', '12345678', 'Fake Employee', 'Fake Approver', 'Hilla', 'Operations',
        'Approved', 'Annual Leave', '', '10/21/2026', '10/21/2026', '1', '', 'Fake Creator', '10/1/2026', 'Fake Manager', '']);
    rewind($stream);
    $leave = UploadedFile::fake()->createWithContent('IRAQ All Leave REPORT-2026-10-22-10-00-00.csv', stream_get_contents($stream));
    fclose($stream);
    $balance = UploadedFile::fake()->createWithContent('Annual Balance.csv',
        "Pers.No.,Total Remaining Annual Leave Days,ANNUAL LEAVE DAYS EMPLOYEE IS/TO BE ENTITELED IN 2026\n12345678,15,20\n");
    $employees = UploadedFile::fake()->createWithContent('HC employees.csv',
        "employee_id,name,branch\n12345679,Fake New,Hilla\n");
    $unknown = UploadedFile::fake()->createWithContent('notes.csv', "a,b,c\n1,2,3\n");

    $detector = app(UploadDetector::class);
    foreach (compact('attendance', 'leave', 'balance', 'employees', 'unknown') as $name => $file) {
        $expected = $name === 'balance' ? 'leave_balance' : $name;
        expect($detector->detect($this->company, $file)['kind'])->toBe($expected);
    }
});

test('a saved mapping belonging to another tenant is never applied', function (): void {
    $file = UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv',
        "Employee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n"
        ."12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n");
    $headers = app(TransactionFile::class)->inspect($file)['headers'];
    $suggested = app(TransactionFile::class)->suggest($headers);
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function () use ($other, $headers): void {
        ImportMapping::create(['company_id' => $other->id,
            'header_signature' => hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR)),
            'headers' => $headers, 'mapping' => [0 => 'device_name']]);
    });

    expect(app(UploadDetector::class)->detect($this->company, $file)['mapping'])->toBe($suggested);
});
