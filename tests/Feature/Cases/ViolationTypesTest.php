<?php

use App\Enums\CaseStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TenantMismatch;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Models\ViolationReport;
use App\Models\ViolationType;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseViolationTypes;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Services\Search\GlobalSearch;
use App\Support\Format\Violation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('managed names follow the viewer language and retain the Arabic snapshot', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $type = ViolationType::factory()->create(['names' => ['ar' => 'شجار', 'en' => 'Fight']]);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $selected = app(CaseViolationTypes::class)->selected($type->id, 'ignored text', $company->id);
    expect($selected)->toBe(['violation_type_id' => $type->id, 'violation_type' => 'شجار']);
    $case = DisciplinaryCase::factory()->create($selected + ['opened_by_user_id' => $admin->id]);
    $report = ViolationReport::factory()->create($selected);
    app()->setLocale('en');
    expect(Violation::name($case))->toBe('Fight')->and(Violation::name($report))->toBe('Fight');
    $this->actingAs($admin)->get('/cases/'.$case->id)->assertOk()->assertSee('Fight');
    $type->update(['names' => ['ar' => 'مشاجرة', 'en' => 'Altercation']]);
    expect(Violation::name($case->fresh()))->toBe('Altercation')
        ->and($case->fresh()->violation_type)->toBe('شجار');
    app()->setLocale('ar');
    expect(Violation::name($case->fresh()))->toBe('مشاجرة')
        ->and(Violation::name($case->fresh(), 'ku'))->toBe('مشاجرة');
    expect(fn () => $type->delete())->toThrow(ImmutableRecordException::class);
});

test('mapping legacy rows records the case event and report audit action', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $type = ViolationType::factory()->create(['names' => ['ar' => 'شجار', 'en' => 'Fight']]);
    $case = DisciplinaryCase::factory()->create(['violation_type' => 'legacy fight', 'opened_by_user_id' => $admin->id]);
    $report = ViolationReport::factory()->create(['violation_type' => 'legacy fight']);
    $this->actingAs($admin);
    app(CaseViolationTypes::class)->mapCase($case, $type->id, $admin);
    app(CaseViolationTypes::class)->mapReport($report, $type->id, $admin);
    expect($case->fresh()->violation_type_id)->toBe($type->id)
        ->and($case->fresh()->violation_type)->toBe('legacy fight')
        ->and($case->events()->where('event_type', 'violation_type_mapped')->first()->payload['old_text'])->toBe('legacy fight')
        ->and($case->events()->where('event_type', 'violation_type_mapped')->first()->payload['type_name_ar'])->toBe('شجار')
        ->and($report->fresh()->violation_type_id)->toBe($type->id)
        ->and(AuditLog::query()->where('action', 'report.violation_type_mapped')->where('entity_id', $report->id)->exists())->toBeTrue();
});

test('inactive and cross-company types cannot be selected or mapped', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'reporter']);
    expect($admin->can('create', ViolationType::class))->toBeTrue()
        ->and($reporter->can('create', ViolationType::class))->toBeFalse();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id]);
    $inactive = ViolationType::factory()->create(['is_active' => false]);
    $foreign = app(TenantContext::class)->runForCompany($b, fn () => ViolationType::factory()->create());
    expect(fn () => app(CaseViolationTypes::class)->selected($inactive->id, null, $a->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(CaseViolationTypes::class)->selected($foreign->id, null, $a->id))->toThrow(ModelNotFoundException::class)
        ->and($reporter->can('update', $inactive))->toBeFalse()
        ->and($admin->can('update', $foreign))->toBeFalse();
    expect(fn () => ViolationType::factory()->create(['company_id' => $b->id]))->toThrow(TenantMismatch::class)
        ->and(fn () => $foreign->update(['names' => ['ar' => 'تعديل', 'en' => 'Edit']]))->toThrow(TenantMismatch::class);
    $this->actingAs($admin);
    expect(fn () => app(CaseViolationTypes::class)->mapCase($case, $foreign->id, $admin))->toThrow(ModelNotFoundException::class);
});

test('required names and composite keys guard managed types', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    expect(fn () => ViolationType::factory()->create(['names' => ['ar' => 'شجار']]))->toThrow(ValidationException::class);
    $case = DisciplinaryCase::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($b, fn () => ViolationType::factory()->create());
    expect(fn () => DB::table('cases')->where('id', $case->id)
        ->update(['violation_type_id' => $foreign->id]))->toThrow(QueryException::class);
});

test('search finds current mapped names and retains legacy text search', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $type = ViolationType::factory()->create(['names' => ['ar' => 'شجار', 'en' => 'Fight']]);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'violation_type' => 'شجار', 'violation_type_id' => $type->id]);
    ViolationReport::factory()->create(['violation_type' => 'شجار', 'violation_type_id' => $type->id]);
    expect(collect(app(GlobalSearch::class)->for($admin, 'Fight')['cases'])->pluck('url'))->toContain(route('cases.show', $case->id))
        ->and(app(GlobalSearch::class)->for($admin, 'Fight')['reports'])->not->toBeEmpty();
});

test('hotspots group by managed type and repeat checks prefer matching ids', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $type = ViolationType::factory()->create(['names' => ['ar' => 'شجار', 'en' => 'Fight']]);
    $first = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'violation_type' => 'شجار', 'violation_type_id' => $type->id,
        'violation_time' => '2026-09-20 12:00:00', 'created_at' => '2026-09-20 12:00:00']);
    $second = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'employee_id' => $first->employee_id, 'branch_id' => $first->branch_id,
        'violation_type' => 'different historical wording', 'violation_type_id' => $type->id,
        'violation_time' => '2026-09-21 12:00:00', 'created_at' => '2026-09-21 12:00:00']);
    $filters = DashboardFilters::fromInput($admin, ['period' => '6']);
    $metrics = app(CaseMetrics::class);
    $key = 'vt:'.$type->id;
    expect(collect($metrics->hotspots($admin, $filters)['violation'])->firstWhere('key', $key)['count'])->toBe(2)
        ->and($metrics->hotspotQuery($admin, $filters, 'violation', $key)->count())->toBe(2)
        ->and(collect($metrics->thresholds($admin, $filters, 2, 90))->firstWhere('violation', $key)['violation_label'])->toBe('Fight')
        ->and(app(CaseInsights::class)->priorBanner($second)['cases'][0]['same_type_repeat'])->toBeTrue();
});
