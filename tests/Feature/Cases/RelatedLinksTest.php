<?php

use App\Ai\PayloadBuilder;
use App\Enums\DocumentType;
use App\Livewire\Cases\ShowCase;
use App\Models\CaseLink;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\Cases\CaseLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function relatedLinkFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $first = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id, 'violation_type' => 'Late arrival']);
    $second = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id, 'violation_type' => 'Follow-up']);

    return compact('company', 'admin', 'first', 'second');
}

test('related links are canonical, visible from both cases, and permanently audited', function (): void {
    $f = relatedLinkFixture();
    $service = app(CaseLinks::class);
    $link = $service->linkRelated($f['second'], $f['first'], 'Same incident', $f['admin']);
    expect($link->case_id)->toBe($f['first']->id)
        ->and($link->linked_case_id)->toBe($f['second']->id)
        ->and($service->visibleRelated($f['first'], $f['admin'])->first()['case']->id)->toBe($f['second']->id)
        ->and($service->visibleRelated($f['second'], $f['admin'])->first()['case']->id)->toBe($f['first']->id)
        ->and($f['first']->events()->where('event_type', 'case_related_linked')->count())->toBe(1)
        ->and($f['second']->events()->where('event_type', 'case_related_linked')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'case.related_linked')->count())->toBe(1);
    expect(fn () => $service->linkRelated($f['first'], $f['second'], null, $f['admin']))->toThrow(DomainException::class);
    expect(fn () => CaseLink::query()->whereKey($link->id)->delete())->toThrow(QueryException::class);

    $annotation = $service->markInError($link, 'Wrong follow-up', $f['admin']);
    expect($service->visibleRelated($f['first'], $f['admin'])->first()['in_error']->reason)->toBe('Wrong follow-up')
        ->and($f['first']->events()->where('event_type', 'case_related_link_in_error')->count())->toBe(1)
        ->and($f['second']->events()->where('event_type', 'case_related_link_in_error')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'case.related_link_in_error')->count())->toBe(1)
        ->and(app(PayloadBuilder::class)->build($f['first'], DocumentType::Recommendations, collect(), $f['admin'])['related_cases'])->toBe([]);
    expect(fn () => $service->markInError($link, 'Again', $f['admin']))->toThrow(DomainException::class);
    expect(fn () => DB::table('case_link_annotations')->where('id', $annotation->id)->update(['reason' => 'Changed']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('case_link_annotations')->where('id', $annotation->id)->delete())
        ->toThrow(QueryException::class);
});

test('candidate and link actions reject invisible, foreign, and same cases', function (): void {
    $f = relatedLinkFixture();
    $service = app(CaseLinks::class);
    $staff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $f['first']->forceFill(['assigned_hr_user_id' => $staff->id])->save();
    expect($service->candidates($staff, $f['first'], '')->pluck('id')->all())->not->toContain($f['second']->id);
    expect(fn () => $service->linkRelated($f['first'], $f['second'], null, $staff))->toThrow(DomainException::class);
    expect(fn () => $service->linkRelated($f['first'], $f['first'], null, $f['admin']))->toThrow(DomainException::class);
    $reporter = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'reporter']);
    expect(fn () => $service->candidates($reporter, $f['first'], ''))->toThrow(DomainException::class);
    expect(fn () => $service->linkRelated($f['first'], $f['second'], null, $reporter))->toThrow(DomainException::class);

    $foreignCompany = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($foreignCompany,
        fn () => DisciplinaryCase::factory()->create());
    expect(fn () => $service->linkRelated($f['first'], $foreign, null, $f['admin']))->toThrow(DomainException::class);
});

test('picker searches normalized employee names and case numbers with a ten-case limit', function (): void {
    $f = relatedLinkFixture();
    $service = app(CaseLinks::class);
    $f['second']->employee->forceFill(['name' => 'أحمد سالم'])->save();
    expect($service->candidates($f['admin'], $f['first'], 'احمد')->pluck('id')->all())->toContain($f['second']->id)
        ->and($service->candidates($f['admin'], $f['first'], (string) $f['second']->case_number)->pluck('id')->all())->toContain($f['second']->id)
        // The number as every screen displays it ("0002") finds the case too.
        ->and($service->candidates($f['admin'], $f['first'], str_pad((string) $f['second']->case_number, 4, '0', STR_PAD_LEFT))->pluck('id')->all())->toContain($f['second']->id);
    foreach (range(1, 11) as $index) {
        DisciplinaryCase::factory()->create(['opened_by_user_id' => $f['admin']->id]);
    }
    expect($service->candidates($f['admin'], $f['first'], ''))->toHaveCount(10);
    $service->linkRelated($f['first'], $f['second'], null, $f['admin']);
    expect($service->candidates($f['admin'], $f['first'], 'احمد')->pluck('id')->all())->not->toContain($f['second']->id);
});

test('related case display and AI context disappear when the viewer loses access', function (): void {
    $f = relatedLinkFixture();
    $service = app(CaseLinks::class);
    $staff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $f['first']->forceFill(['assigned_hr_user_id' => $staff->id])->save();
    $f['second']->forceFill(['assigned_hr_user_id' => $staff->id])->save();
    $service->linkRelated($f['first'], $f['second'], null, $staff);
    expect($service->visibleRelated($f['first'], $staff))->toHaveCount(1)
        ->and(app(PayloadBuilder::class)->build($f['first'], DocumentType::DefenseLetter, collect(), $staff)['related_cases'])->toHaveCount(1);

    $f['second']->forceFill(['assigned_hr_user_id' => null])->save();
    expect($service->visibleRelated($f['first'], $staff))->toHaveCount(0)
        ->and(app(PayloadBuilder::class)->build($f['first'], DocumentType::Recommendations, collect(), $staff)['related_cases'])->toBe([]);
    Livewire::actingAs($staff)->test(ShowCase::class, ['case' => (string) $f['first']->id])
        ->assertDontSee('1 related case')->assertDontSee('Follow-up')->assertDontSee('A related case was linked');
});

test('AI receives only ten visible minimal related case facts', function (): void {
    $f = relatedLinkFixture();
    $service = app(CaseLinks::class);
    $service->linkRelated($f['first'], $f['second'], null, $f['admin']);
    foreach (range(1, 11) as $index) {
        $other = DisciplinaryCase::factory()->create(['opened_by_user_id' => $f['admin']->id,
            'violation_type' => 'Follow-up '.$index]);
        $service->linkRelated($f['first'], $other, null, $f['admin']);
    }
    $other->forceFill(['violation_type' => $other->employee->name.' 07701234567'])->save();
    $payload = app(PayloadBuilder::class)->build($f['first'], DocumentType::Recommendations, collect(), $f['admin']);
    expect($payload['related_cases'])->toHaveCount(10)
        ->and(array_keys($payload['related_cases'][0]))->toBe(['date', 'violation_type', 'outcome']);
    expect(json_encode($payload['related_cases']))->not->toContain($f['second']->employee?->name, $other->employee->name, '07701234567');
    expect(app(PayloadBuilder::class)->build($f['first'], DocumentType::DecisionLetter, collect(), $f['admin']))
        ->not->toHaveKey('related_cases');
});

test('a committee member who sees only one case sees no related case or banner count', function (): void {
    $f = relatedLinkFixture();
    app(CaseLinks::class)->linkRelated($f['first'], $f['second'], 'Same incident', $f['admin']);
    $member = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'committee_member']);
    $pool = CommitteePoolMember::factory()->create(['user_id' => $member->id]);
    CommitteeSessionAttendance::factory()->create(['case_id' => $f['first']->id, 'committee_pool_member_id' => $pool->id]);

    expect(app(CaseLinks::class)->visibleRelated($f['first'], $member))->toHaveCount(0);
    Livewire::actingAs($member)->test(ShowCase::class, ['case' => (string) $f['first']->id])
        ->assertDontSee('1 related case')->assertDontSee('Follow-up')->assertDontSee('Same incident')
        ->assertDontSee('A related case was linked');
});

test('HR can link and annotate a related case from the case card', function (): void {
    $f = relatedLinkFixture();
    Livewire::actingAs($f['admin'])->test(ShowCase::class, ['case' => (string) $f['first']->id])
        ->set('relatedSearch', (string) $f['second']->case_number)
        ->set('relatedCaseId', (string) $f['second']->id)
        ->set('relatedReason', 'Same incident')
        ->call('linkRelated')->assertHasNoErrors()->assertSee('1 related case')->assertSee('Same incident');
    $link = CaseLink::query()->where('link_type', 'related')->firstOrFail();
    Livewire::actingAs($f['admin'])->test(ShowCase::class, ['case' => (string) $f['second']->id])
        ->assertSee('Same incident')
        ->set('relatedErrorReasons.'.$link->id, 'Wrong case')
        ->call('markRelatedInError', $link->id)->assertHasNoErrors()->assertSee('Linked in error: Wrong case');
});
