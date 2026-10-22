<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\Meal;
use App\Models\Attendance\Person;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\DeviceRegistry;
use App\Services\Attendance\PersonClassifier;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

function processingTransaction(string $code, string $at, string $state, Device $device, string $department = 'Operations'): Transaction
{
    return Transaction::create([
        'company_id' => $device->company_id, 'person_code' => $code, 'person_name' => 'Fake Person',
        'department_label' => $department, 'punch_date' => substr($at, 0, 10),
        'punch_time' => substr($at, 11, 5), 'punched_at' => $at, 'punch_state' => $state,
        'serial_number' => $device->serial_number, 'device_id' => $device->id,
        'device_name' => $device->name, 'data_source' => 'Face',
        'first_batch_id' => app('attendance.testing.batch')->id,
    ]);
}

function processingDevice(string $serial, string $role, string $status = 'approved'): Device
{
    $device = Device::factory()->create(['serial_number' => $serial, 'status' => $status]);
    if ($status === 'approved') {
        DeviceSetting::factory()->create([
            'device_id' => $device->id, 'branch_id' => app('attendance.testing.branch')->id,
            'role' => $role, 'effective_from' => '2026-10-01',
        ]);
    }

    return $device;
}

function processCodes(array $codes = ['12345678'], string $from = '2026-10-20 00:00:00', string $to = '2026-10-20 23:59:59'): void
{
    app(AttendanceProcessor::class)->run(app('attendance.testing.company'), $codes, $from, $to, 'manual');
}

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['name' => 'Hilla']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => '12345678']);
    $this->batch = ImportBatch::factory()->create();
    app()->instance('attendance.testing.company', $this->company);
    app()->instance('attendance.testing.batch', $this->batch);
    app()->instance('attendance.testing.branch', $this->branch);
    app(PersonClassifier::class)->classify($this->company, '12345678', 'Operations');
    $this->actingAs($this->actor);
});

test('consecutive check-ins repeat while a close out-to-in break stays used', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    $out = processingDevice('OUT-1', 'check_out');
    $first = processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $in);
    $repeat = processingTransaction('12345678', '2026-10-20 08:15:00', 'Check In', $in);
    $breakOut = processingTransaction('12345678', '2026-10-20 12:00:00', 'Check Out', $out);
    $breakIn = processingTransaction('12345678', '2026-10-20 12:05:00', 'Check In', $in);
    processCodes();

    $punches = Punch::query()->whereNull('superseded_by_run_id')->pluck('status', 'transaction_id');
    expect($punches[$first->id])->toBe('used')->and($punches[$repeat->id])->toBe('repeat')
        ->and($punches[$breakOut->id])->toBe('used')->and($punches[$breakIn->id])->toBe('used')
        ->and(DataIssue::query()->count())->toBe(0);
});

test('repeated check-outs keep the last and a group cannot span beyond the configured gap', function (): void {
    $out = processingDevice('OUT-1', 'check_out');
    $early = processingTransaction('12345678', '2026-10-20 17:00:00', 'Check Out', $out);
    $late = processingTransaction('12345678', '2026-10-20 17:10:00', 'Check Out', $out);
    $nextDay = processingTransaction('12345678', '2026-10-21 10:00:00', 'Check Out', $out);
    processCodes(to: '2026-10-21 23:59:59');

    $punches = Punch::query()->whereNull('superseded_by_run_id')->get()->keyBy('transaction_id');
    expect($punches[$early->id]->status)->toBe('repeat')
        ->and($punches[$early->id]->reason_code)->toBe('repeated_check_out')
        ->and($punches[$late->id]->status)->toBe('used')
        ->and($punches[$nextDay->id]->status)->toBe('used')
        ->and($punches[$late->id]->group_key)->not->toBe($punches[$nextDay->id]->group_key);
});

test('restaurant punches produce one meal with repetitions and never attendance punches', function (): void {
    $restaurant = processingDevice('MEAL-1', 'restaurant');
    $first = processingTransaction('12345678', '2026-10-20 12:00:00', '255', $restaurant);
    processingTransaction('12345678', '2026-10-20 12:05:00', '255', $restaurant);
    app(PersonClassifier::class)->classify($this->company, 'G-1', 'Guest');
    processingTransaction('G-1', '2026-10-20 12:10:00', '255', $restaurant, 'Guest');
    processCodes(['12345678', 'G-1']);

    $meal = Meal::query()->where('person_code', '12345678')->whereNull('superseded_by_run_id')->firstOrFail();
    expect($meal->punch_count)->toBe(2)->and($meal->repeat_count)->toBe(1)
        ->and($meal->first_transaction_id)->toBe($first->id)
        ->and(Punch::query()->count())->toBe(0)
        ->and(Meal::query()->where('person_code', 'G-1')->value('department_label'))->toBe('Guest');
});

test('pending punches are unused until approval and only declared branch coverage is confirmed', function (): void {
    $pending = processingDevice('PENDING-1', 'check_in', 'pending');
    $transaction = processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $pending);
    $otherBranch = Branch::factory()->create(['name' => 'Karbala']);
    foreach ([$this->branch, $otherBranch] as $branch) {
        DB::table('attendance_coverage_intervals')->insert([
            'company_id' => $this->company->id, 'batch_id' => $this->batch->id, 'branch_id' => $branch->id,
            'coverage_from' => '2026-10-20 00:00:00', 'coverage_to' => '2026-10-20 23:59:59',
            'kind' => 'declared', 'is_confirmed' => false, 'created_at' => now(),
        ]);
    }
    DB::table('attendance_transaction_sources')->insert([
        'company_id' => $this->company->id, 'transaction_id' => $transaction->id,
        'batch_id' => $this->batch->id, 'row_number' => 3, 'duplicate_in_file' => false,
        'person_code' => '12345678', 'person_name' => 'Fake Person', 'department_label' => 'Operations',
        'punch_date' => '2026-10-20', 'punch_time' => '08:00', 'punch_state' => 'Check In',
        'serial_number' => $pending->serial_number, 'device_name' => $pending->name,
        'data_source' => 'Face', 'created_at' => now(),
    ]);
    processCodes();
    expect(Punch::query()->whereNull('superseded_by_run_id')->value('status'))->toBe('unused_device');

    app(DeviceRegistry::class)->approve($pending, 'check_in', $this->branch->id, $this->actor);
    Queue::assertPushed(ProcessAttendanceJob::class, 1);
    $job = Queue::pushed(ProcessAttendanceJob::class)->first();
    app()->call([$job, 'handle']);
    expect(Punch::query()->whereNull('superseded_by_run_id')->where('transaction_id', $transaction->id)->value('status'))->toBe('used')
        ->and(Punch::query()->where('transaction_id', $transaction->id)->count())->toBe(2)
        ->and(DB::table('attendance_coverage_intervals')->where('branch_id', $this->branch->id)->value('is_confirmed'))->toBeTruthy()
        ->and(DB::table('attendance_coverage_intervals')->where('branch_id', $otherBranch->id)->value('is_confirmed'))->toBeFalsy();
});

test('device role changes rebuild neighbouring groups and restaurant days', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    $middle = processingDevice('MIDDLE-1', 'check_out');
    processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $in);
    $changed = processingTransaction('12345678', '2026-10-20 08:05:00', 'Check Out', $middle);
    processingTransaction('12345678', '2026-10-20 08:10:00', 'Check In', $in);
    processCodes();
    expect(Punch::query()->whereNull('superseded_by_run_id')->where('status', 'used')->count())->toBe(3);

    app(DeviceRegistry::class)->change($middle, 'check_in', $this->branch->id, $this->actor, '2026-10-20');
    $job = Queue::pushed(ProcessAttendanceJob::class)->last();
    app()->call([$job, 'handle']);
    expect(Punch::query()->whereNull('superseded_by_run_id')->where('status', 'used')->count())->toBe(1)
        ->and(Punch::query()->whereNull('superseded_by_run_id')->where('status', 'repeat')->count())->toBe(2);

    app(DeviceRegistry::class)->change($middle, 'restaurant', $this->branch->id, $this->actor, '2026-10-20');
    $nextJob = Queue::pushed(ProcessAttendanceJob::class)->last();
    expect($nextJob->uniqueId())->not->toBe($job->uniqueId());
    app()->call([$nextJob, 'handle']);
    expect(Punch::query()->whereNull('superseded_by_run_id')->where('transaction_id', $changed->id)->count())->toBe(0)
        ->and(Meal::query()->whereNull('superseded_by_run_id')->value('punch_count'))->toBe(1);
});

test('effective-dated retirement leaves earlier punches intact and excludes later ones', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    $before = processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $in);
    $after = processingTransaction('12345678', '2026-10-21 08:00:00', 'Check In', $in);
    processCodes(to: '2026-10-21 23:59:59');

    app(DeviceRegistry::class)->retire($in, $this->actor, '2026-10-21');
    $job = Queue::pushed(ProcessAttendanceJob::class)->last();
    app()->call([$job, 'handle']);
    $current = Punch::query()->whereNull('superseded_by_run_id')->pluck('status', 'transaction_id');
    expect($current[$before->id])->toBe('used')
        ->and($current[$after->id])->toBe('unused_device');
});

test('an employee created later reclassifies an unknown person and rebuilds current punches', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    processingTransaction('LATER-1', '2026-10-20 08:00:00', 'Check In', $in);
    app(PersonClassifier::class)->classify($this->company, 'LATER-1', 'Operations');
    processCodes(['LATER-1']);
    expect(Person::query()->where('person_code', 'LATER-1')->value('kind'))->toBe('unknown')
        ->and(Punch::query()->whereNull('superseded_by_run_id')->value('status'))->toBe('non_employee');

    app(EmployeeHistory::class)->create([
        'company_id' => $this->company->id, 'employee_code' => 'LATER-1',
        'branch_id' => $this->branch->id, 'name' => 'Fake New Employee',
        'hire_date' => '2026-10-20', 'gender' => 'male',
    ], ['name' => 'Fake New Employee'], '2026-10-20', 'manual', $this->actor);
    expect(Person::query()->where('person_code', 'LATER-1')->value('kind'))->toBe('employee')
        ->and(DataIssue::query()->where('type', 'unknown_person')->where('status', 'open')->count())->toBe(0);
    $job = Queue::pushed(ProcessAttendanceJob::class)->last();
    app()->call([$job, 'handle']);
    expect(Punch::query()->whereNull('superseded_by_run_id')->value('status'))->toBe('used');
});

test('an employee history change queues reprocessing without changing the raw punch', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    $transaction = processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $in);
    processCodes();
    app(EmployeeHistory::class)->record($this->employee, ['department' => 'New Department'], '2026-10-20', 'manual', $this->actor);

    Queue::assertPushed(ProcessAttendanceJob::class);
    $job = Queue::pushed(ProcessAttendanceJob::class)->last();
    app()->call([$job, 'handle']);
    expect(Punch::query()->whereNull('superseded_by_run_id')->where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and(Transaction::query()->findOrFail($transaction->id)->department_label)->toBe('Operations');
});

test('repeated and overlapping processing leaves exactly one current result per transaction and person day', function (): void {
    $in = processingDevice('IN-1', 'check_in');
    $restaurant = processingDevice('MEAL-1', 'restaurant');
    $transaction = processingTransaction('12345678', '2026-10-20 08:00:00', 'Check In', $in);
    processingTransaction('12345678', '2026-10-20 12:00:00', '255', $restaurant);
    processCodes();
    processCodes(from: '2026-10-19 00:00:00', to: '2026-10-21 23:59:59');

    expect(Punch::query()->whereNull('superseded_by_run_id')->where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and(Meal::query()->whereNull('superseded_by_run_id')->where('person_code', '12345678')->count())->toBe(1)
        ->and(Punch::query()->where('transaction_id', $transaction->id)->count())->toBe(2)
        ->and(ProcessingRun::query()->where('status', 'completed')->count())->toBe(4);
});

test('processing cannot run against another company from the current tenant', function (): void {
    $other = Company::factory()->create();

    expect(fn () => app(AttendanceProcessor::class)->run(
        $other, ['12345678'], '2026-10-20 00:00:00', '2026-10-20 23:59:59', 'manual',
    ))->toThrow(HttpException::class);
});
