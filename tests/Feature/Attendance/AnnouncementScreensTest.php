<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\HolidayReview;
use App\Filament\Admin\Pages\Attendance\HolidaysPage;
use App\Filament\Admin\Pages\Attendance\UploadCenter;
use App\Jobs\Attendance\ReadHolidaySourceJob;
use App\Livewire\Attendance\AnnouncementReview;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Attendance\ManualPunch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AnnouncementPreview;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\UploadDetector;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('announcement extensions bypass spreadsheet inspection', function (): void {
    foreach (['pdf', 'png', 'jpg', 'jpeg', 'eml', 'msg'] as $extension) {
        $file = UploadedFile::fake()->createWithContent('announcement.'.$extension, 'test');
        expect(app(UploadDetector::class)->detect($this->company, $file)['kind'])->toBe('announcement');
    }
});

test('the preview counts only selected people with local in or out punches', function (): void {
    $selected = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '00001001',
        'department' => 'Events', 'hire_date' => '2020-01-01']);
    $other = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '00001002',
        'department' => 'Office', 'hire_date' => '2020-01-01']);
    foreach ([$selected, $other] as $employee) {
        ManualPunch::factory()->create(['company_id' => $this->company->id,
            'employee_id' => $employee->id, 'created_by' => $this->actor->id,
            'punched_at' => '2026-10-08 23:30:00', 'role' => 'check_in']);
    }
    $preview = app(AnnouncementPreview::class)->forDate($this->company, '2026-10-08', [
        'everyone' => false, 'departments' => ['Events'], 'employee_ids' => [],
    ]);
    expect($preview['count'])->toBe(1)->and($preview['with_punches'])->toBe(1)
        ->and($preview['sample'][0]['code'])->toBe('00001001');
});

test('Upload Center stores an announcement and shows its review card in the row', function (): void {
    Storage::fake('local');
    Queue::fake();
    $file = UploadedFile::fake()->createWithContent('market.eml',
        "From: hr@example.test\nSubject: Market day\nContent-Type: text/plain\n\nEvents staff work away.");
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [$file])
        ->assertHasNoErrors()->assertSee('Announcement')
        ->call('import')->assertHasNoErrors();
    $source = HolidaySource::query()->where('company_id', $this->company->id)->sole();
    expect($page->get('rows')[0]['source_id'])->toBe($source->id);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->assertSee('Reading this announcement');
    Queue::assertPushed(ReadHolidaySourceJob::class);
});

test('a pending card fills a completed proposal on poll without replacing HR edits', function (): void {
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'pending', 'proposal' => null]);
    $card = Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->set('name', 'HR checked name');
    $source->update(['ai_status' => 'done', 'proposal' => [
        'kind' => 'field_work', 'name_en' => 'Event day', 'summary' => 'Events staff work away.',
        'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08'],
            ['from' => '2026-10-10', 'to' => '2026-10-10']],
        'departments' => ['Events'], 'branch_names' => [], 'legal_entities' => [], 'work_patterns' => [],
    ]]);
    $card->call('refreshProposal')->assertSet('kind', 'field_work')
        ->assertSet('name', 'HR checked name')->assertSet('from', '2026-10-08')
        ->assertSet('extraRanges.0.from', '2026-10-10')->assertSet('departments', ['Events']);
    $card->set('from', '2026-10-09')->call('refreshProposal')->assertSet('from', '2026-10-09');
});

test('review requires dates and an explicit audience and approves one version', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'employee_code' => '00001234', 'department' => 'Events', 'hire_date' => '2020-01-01']);
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'name_en' => 'Market day', 'name_ar' => null, 'summary' => 'Events staff work at the market.',
            'ranges' => [], 'departments' => ['Events'], 'suggested_departments' => [],
            'scope_quotes' => ['Events staff work away from devices.'], 'exception_quotes' => [],
            'legal_entities' => [], 'branch_names' => [], 'work_patterns' => [],
            'confidence' => 'high', 'notes' => [], 'expected_people' => 2,
            'mentions_missing_attachment' => false,
        ]]);

    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->assertSee('Which date or dates')
        ->call('approve')->assertHasErrors(['from']);

    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->set('from', '2026-10-08')->set('to', '2026-10-08')
        ->assertSee('The announcement says 2 people. You selected 1.')
        ->call('addRange')->set('extraRanges.0.from', '2026-10-09')->set('extraRanges.0.to', '2026-10-09')
        ->call('approve')->assertHasNoErrors()->assertSee('Recalculating');
    expect(HolidayVersion::query()->where('source_id', $source->id)->count())->toBe(1)
        ->and(HolidayVersion::query()->where('source_id', $source->id)->firstOrFail()->ranges)->toHaveCount(2)
        ->and(HolidayVersion::query()->where('source_id', $source->id)->firstOrFail()->reason)
        ->toContain('Approved from announcement');
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->assertSee('is approved');
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->call('approve')->assertStatus(409);
});

test('another company announcement cannot be opened', function (): void {
    $other = Company::factory()->create();
    $source = app(TenantContext::class)->runForCompany($other,
        fn () => HolidaySource::factory()->create(['company_id' => $other->id]));
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->assertNotFound();
});

test('a notice can be closed and reopened without creating a holiday', function (): void {
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'notice', 'summary' => 'A schedule reminder.', 'name_en' => 'Reminder',
            'ranges' => [], 'departments' => [], 'scope_quotes' => [], 'exception_quotes' => [],
        ]]);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->call('close')->assertSee('Reopen')->call('reopen')->assertSee('What it is');
    expect($source->fresh()->closed_at)->toBeNull()
        ->and(HolidayVersion::query()->where('source_id', $source->id)->count())->toBe(0);
});

test('field work for everyone needs the count confirmation', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'hire_date' => '2020-01-01']);
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'summary' => 'The team is at a market.', 'name_en' => 'Market day',
            'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08']],
            'departments' => [], 'scope_quotes' => [], 'exception_quotes' => [],
        ]]);
    $review = Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->set('who', 'everyone')->assertSee('Confirm field work for all 1 people.');
    $review->call('approve')->assertStatus(422);
    expect(HolidayVersion::query()->where('source_id', $source->id)->count())->toBe(0);
});

test('withdrawn source can be approved again', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'department' => 'Events', 'hire_date' => '2020-01-01']);
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'name_en' => 'Event day', 'summary' => 'Events work away.',
            'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08']],
            'departments' => ['Events'], 'branch_names' => [], 'legal_entities' => [], 'work_patterns' => [],
        ]]);
    $first = app(Holidays::class)->approveFromSource($source, [
        'kind' => 'field_work', 'name_en' => 'Event day', 'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08']],
        'departments' => ['Events'], 'everyone' => false,
    ], $this->actor);
    app(Holidays::class)->withdraw($first, $this->actor, 'Event cancelled');
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->assertSee('What it is');
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->call('approve')->assertHasNoErrors();
    expect(HolidayVersion::query()->where('source_id', $source->id)->where('status', 'approved')->count())->toBe(2);
    $active = HolidayVersion::query()->where('source_id', $source->id)->where('status', 'approved')->latest('id')->firstOrFail();
    Livewire::test(HolidaysPage::class)->assertSee('Events')->assertSee('A group works away from devices');
    Livewire::withQueryParams(['holiday' => $active->holiday_id])->test(HolidayReview::class)
        ->assertSet('departments', ['Events'])->assertSet('kind', 'field_work');
});

test('an active draft blocks another approval and keeps its details link', function (): void {
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'holiday', 'name_en' => 'Event day', 'ranges' => [],
        ]]);
    app(Holidays::class)->create($this->company, [
        'name_en' => 'Event day', 'source_id' => $source->id,
    ], $this->actor);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->assertSee('A draft exists')->assertSee('See details')->call('approve')->assertStatus(409);
});

test('department search, suggestions and chips keep guesses under HR control', function (): void {
    foreach (['Events', 'Accounts'] as $department) {
        Employee::factory()->create(['company_id' => $this->company->id,
            'branch_id' => $this->branch->id, 'department' => $department, 'hire_date' => '2020-01-01']);
    }
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'name_en' => 'Event day', 'ranges' => [],
            'departments' => [], 'suggested_departments' => ['Events'],
        ]]);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->assertSet('departments', [])->set('who', 'selected')->assertSee('Possible department: Events')
        ->set('departmentSearch', 'Event')
        ->assertSee('Events')->assertDontSee('Accounts')
        ->call('addDepartment', 'Events')->assertSet('departments', ['Events'])
        ->call('removeDepartment', 'Events')->assertSet('departments', []);
});

test('unknown employee codes are shown without creating employees', function (): void {
    Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '00001001', 'hire_date' => '2020-01-01']);
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'name_en' => 'Event day', 'ranges' => [], 'departments' => [],
        ]]);
    $before = Employee::query()->where('company_id', $this->company->id)->count();
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->set('peopleFile', UploadedFile::fake()->createWithContent('people.csv', "code\n00001001\n99999999\n"))
        ->assertSee('1 employee codes match nobody: 99999999');
    expect(Employee::query()->where('company_id', $this->company->id)->count())->toBe($before);
});

test('an inactive account cannot review an announcement', function (): void {
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id]);
    $inactive = User::factory()->create(['company_id' => $this->company->id,
        'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    actingInAdminTenant($this, $inactive, $this->company);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])->assertForbidden();
});

test('a field day needs departments or a list, because a branch alone would cover everyone in it', function (): void {
    $source = HolidaySource::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'ai_status' => 'done', 'proposal' => [
            'kind' => 'field_work', 'name_en' => 'Event day', 'branch_names' => [$this->branch->name],
            'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08']], 'departments' => [],
        ]]);
    Livewire::test(AnnouncementReview::class, ['sourceId' => $source->id])
        ->assertSet('who', '')->set('who', 'selected')
        ->assertSee('Who took part?')
        ->call('approve')->assertStatus(422);
    expect(HolidayVersion::query()->where('source_id', $source->id)->count())->toBe(0);
});
