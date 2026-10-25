<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DailyAttendance;
use App\Filament\Admin\Pages\Attendance\HolidayReview;
use App\Filament\Admin\Pages\Attendance\HolidaysPage;
use App\Filament\Admin\Pages\Attendance\UploadHoliday;
use App\Jobs\Attendance\ReadHolidaySourceJob;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\Day;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Ai\HolidayClient;
use App\Services\Attendance\Ai\HolidayReader;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setTenant(null);
    $this->other = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->other, function (): array {
        $branch = Branch::factory()->create(['company_id' => $this->other->id]);

        return [
            'branch' => $branch,
            'holiday' => Holiday::factory()->create(['company_id' => $this->other->id, 'name' => 'Foreign holiday']),
            'source' => HolidaySource::factory()->create(['company_id' => $this->other->id]),
            'hr' => User::factory()->create(['company_id' => $this->other->id, 'primary_role' => UserRole::HrStaff]),
        ];
    });
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'legal_entity' => 'Al-Waha']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

function holidayScreenDraft(Company $company, User $actor): Holiday
{
    return app(Holidays::class)->create($company, [
        'name_en' => 'Founders Day', 'name_ar' => 'يوم التأسيس',
        'ranges' => [['from' => '2026-10-19', 'to' => '2026-10-19']],
    ], $actor);
}

test('holiday list filters status and year without showing another company', function (): void {
    $holiday = holidayScreenDraft($this->company, $this->actor);
    Livewire::test(HolidaysPage::class)->assertSee('Founders Day')->assertDontSee('Foreign holiday')
        ->set('status', 'approved')->assertDontSee('Founders Day')
        ->set('status', 'draft')->set('year', '2026')->assertSee('Founders Day')
        ->set('year', '2025')->assertDontSee('Founders Day');
    expect($holiday->currentVersion->version)->toBe(1);
});

test('upload accepts a file and each reading state has a manual path', function (): void {
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 fake announcement');
    $page = Livewire::test(UploadHoliday::class)->set('sourceFile', $file)->call('uploadAnnouncement')->assertHasNoErrors()
        ->assertSee('Reading')->assertSee('Enter by hand');
    $source = HolidaySource::query()->where('company_id', $this->company->id)->firstOrFail();
    Queue::assertPushed(ReadHolidaySourceJob::class);
    foreach (['done' => 'Proposal ready', 'failed' => 'Reading failed',
        'unavailable' => 'AI reading unavailable'] as $status => $label) {
        $source->update(['ai_status' => $status, 'error_code' => match ($status) {
            'failed' => 'refused', 'unavailable' => 'no_key', default => null,
        }]);
        $page->call('$refresh')->assertSee($label);
        if ($status === 'failed') {
            $page->assertSee('AI reading was refused');
        }
    }
});

test('a completed proposal makes a draft and leaves it unapproved', function (): void {
    config()->set('services.anthropic.key', 'test-only-key');
    $proposal = [
        'name_en' => 'Foundation Day', 'name_ar' => null,
        'ranges' => [['from' => '2026-10-19', 'to' => '2026-10-19']],
        'legal_entities' => ['Al-Waha'], 'branch_names' => [$this->branch->name],
        'work_patterns' => ['office'], 'exception_quotes' => [], 'notes' => [],
        'scope_quotes' => ['Company announcement'], 'confidence' => 'high',
    ];
    app()->instance(HolidayClient::class, new class($proposal) extends HolidayClient
    {
        public function __construct(private array $proposal) {}

        public function send(array $blocks, array $known, array $schema, string $system, ?callable $onAttempt = null, int $maxAttempts = 3): array
        {
            $result = ['stop_reason' => 'end_turn', 'text' => json_encode($this->proposal, JSON_THROW_ON_ERROR),
                'model' => 'test-model', 'input_tokens' => 1, 'output_tokens' => 1];
            if ($onAttempt !== null) {
                $onAttempt('succeeded', null, $result);
            }

            return $result;
        }
    });
    $file = UploadedFile::fake()->createWithContent('notice.eml',
        file_get_contents(base_path('tests/fixtures/holidays/notice.eml')));
    $page = Livewire::test(UploadHoliday::class)->set('sourceFile', $file)->call('uploadAnnouncement')->assertHasNoErrors();
    $source = HolidaySource::query()->where('company_id', $this->company->id)->firstOrFail();
    (new ReadHolidaySourceJob($this->company->id, $source->id))->handle(app(HolidayReader::class));
    $page->call('$refresh')->assertSee('Proposal ready')->call('createDraft')->assertHasNoErrors();
    $holiday = Holiday::query()->where('company_id', $this->company->id)->firstOrFail();
    expect($holiday->status)->toBe('draft')
        ->and($holiday->currentVersion->source_id)->toBe($source->id);
});

test('manual draft approval editing withdrawal and rejection require reasons and retain history', function (): void {
    $page = Livewire::test(HolidayReview::class)
        ->set('nameEn', 'Founders Day')->set('ranges', [['from' => '2026-10-19', 'to' => '2026-10-19']])
        ->call('save')->assertHasNoErrors();
    $holiday = Holiday::query()->where('company_id', $this->company->id)->firstOrFail();
    expect($holiday->status)->toBe('draft');

    $review = Livewire::withQueryParams(['holiday' => $holiday->id])->test(HolidayReview::class);
    $review->call('approve')->assertHasErrors(['reason']);
    $review->set('ranges', [['from' => '2026-10-21', 'to' => '2026-10-20']])
        ->set('reason', 'Published')->call('approve')->assertHasErrors();
    $review->set('ranges', [['from' => '2026-10-19', 'to' => '2026-10-19']])
        ->set('branchIds', [$this->foreign['branch']->id])->call('approve')->assertHasErrors();
    $review->set('branchIds', [$this->branch->id])->call('approve')->assertHasNoErrors();
    expect($holiday->fresh()->status)->toBe('approved');
    $review->set('nameEn', 'Corrected Day')->set('reason', 'Corrected notice')->call('save')->assertHasNoErrors()
        ->assertSee('History')->assertSee('English name')->assertSee('Founders Day')->assertSee('Corrected Day');
    expect($holiday->fresh()->currentVersion->version)->toBe(3);
    $review->set('reason', 'Notice withdrawn')->call('withdraw')->assertHasNoErrors();
    expect($holiday->fresh()->status)->toBe('withdrawn')
        ->and($holiday->fresh()->versions()->count())->toBe(4);

    $another = holidayScreenDraft($this->company, $this->actor);
    Livewire::withQueryParams(['holiday' => $another->id])->test(HolidayReview::class)
        ->call('reject')->assertHasErrors(['reason'])
        ->set('reason', 'Not an official notice')->call('reject')->assertHasNoErrors();
    expect($another->fresh()->status)->toBe('rejected');
});

test('all source parts are navigable and private downloads reject another company', function (): void {
    Storage::fake('local');
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id, 'uploaded_by' => $this->actor->id,
        'file_path' => 'attendance/holidays/'.$this->company->id.'/originals/notice.pdf',
        'parts' => [
            ['kind' => 'text', 'name' => 'body.txt', 'path' => 'attendance/holidays/'.$this->company->id.'/1/parts/0001.txt'],
            ['kind' => 'image', 'name' => 'page.png', 'path' => 'attendance/holidays/'.$this->company->id.'/1/parts/0002.png'],
        ],
    ]);
    $parts = $source->parts;
    foreach ($parts as $index => $part) {
        $parts[$index]['path'] = 'attendance/holidays/'.$this->company->id.'/'.$source->id.'/parts/'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT).($index === 0 ? '.txt' : '.png');
        Storage::disk('local')->put($parts[$index]['path'], 'private content');
    }
    $source->update(['parts' => $parts]);
    Storage::disk('local')->put($source->file_path, 'original');
    $page = Livewire::withQueryParams(['source' => $source->id])->test(HolidayReview::class)
        ->assertSee('body.txt')->assertSee('page.png')->call('nextPart')->assertSet('partIndex', 1)
        ->call('previousPart')->assertSet('partIndex', 0);
    $page->call('selectPart', 1)->assertSet('partIndex', 1);
    $this->get(route('attendance.holidays.original', [$this->company->id, $source->id]))->assertOk();
    $this->get(route('attendance.holidays.part', [$this->company->id, $source->id, 0]))->assertOk();
    $this->get(route('attendance.holidays.original', [$this->company->id, $this->foreign['source']->id]))->assertNotFound();
    $this->get(route('attendance.holidays.part', [$this->company->id, $this->foreign['source']->id, 0]))->assertNotFound();
    Livewire::withQueryParams(['source' => $this->foreign['source']->id])->test(HolidayReview::class)->assertNotFound();
    Livewire::withQueryParams(['holiday' => $this->foreign['holiday']->id])->test(HolidayReview::class)->assertNotFound();
});

test('company HR and platform admin may review while an inactive account cannot', function (): void {
    $holiday = holidayScreenDraft($this->company, $this->actor);
    Livewire::withQueryParams(['holiday' => $holiday->id])->test(HolidayReview::class)->assertOk();
    actingInAdminTenant($this, $this->foreign['hr'], $this->company);
    Livewire::test(HolidaysPage::class)->assertForbidden();
    actingInAdminTenant($this, $this->actor, $this->company);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    Livewire::withQueryParams(['holiday' => $holiday->id])->test(HolidayReview::class)->assertOk();
    $inactive = User::factory()->create(['company_id' => $this->company->id,
        'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    actingInAdminTenant($this, $inactive, $this->company);
    Livewire::test(HolidaysPage::class)->assertForbidden();
    Livewire::test(UploadHoliday::class)->assertForbidden();
    Livewire::withQueryParams(['holiday' => $holiday->id])->test(HolidayReview::class)->assertForbidden();
});

test('daily and employee records link their holiday names', function (): void {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $holiday = holidayScreenDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published notice');
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'work_date' => '2026-10-19',
        'status' => 'holiday_worked', 'holiday_ids' => [$holiday->id], 'presence_minutes' => 480]);
    Livewire::test(DailyAttendance::class)->set('date', '2026-10-19')->assertSee('Founders Day');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->set('from', '2026-10-19')->set('to', '2026-10-19')->assertSee('Founders Day');
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-19', 'to' => '2026-10-19']);
    $summary = app(AttendanceSummary::class);
    expect($summary->totals($filters)['days']['holiday_worked'])->toBe(1)
        ->and($summary->drillDown($filters, 'holiday_worked'))->toBe([$this->employee->id]);
    Livewire::test(HrDashboard::class)->assertSee('Holiday work days')->assertSee('Hours');
    Livewire::withQueryParams(['tile' => 'holiday_worked', 'from' => '2026-10-19', 'to' => '2026-10-19'])
        ->test(DashboardEmployees::class)->assertSee($this->employee->name);
});

test('upload without a file shows the required message and the form does not call Livewire upload helper', function (): void {
    // A page method named "upload" is shadowed by Livewire's own $wire.upload() in the browser.
    Livewire::test(UploadHoliday::class)->call('uploadAnnouncement')->assertHasErrors(['sourceFile' => 'required']);
    expect(file_get_contents(resource_path('views/filament/admin/pages/attendance/holiday-upload.blade.php')))
        ->toContain('wire:submit="uploadAnnouncement"');
});
