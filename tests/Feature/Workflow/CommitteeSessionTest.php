<?php

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ConflictClearance;
use App\Support\Tenancy\TenantContext;

function committeeFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create([
        'status' => CaseStatus::ResponseReceived, 'law_version_id' => $law->id,
        'opened_by_user_id' => $hr->id, 'defense_letter_issued_at' => '2026-06-18 09:00:00',
    ]);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-06-18']);
    $roles = [PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion, PoolRole::Hr];
    $members = collect($roles)->map(fn ($role) => CommitteePoolMember::factory()->create([
        'mandate_id' => $mandate->id, 'role_type' => $role,
    ]));

    return compact('company', 'hr', 'law', 'case', 'mandate', 'members');
}

function sessionVotes($members, array $votes): array
{
    return collect($votes)->map(fn ($vote, $index) => [
        'pool_member_id' => $members[$index]->id, 'vote' => $vote,
    ])->all();
}

test('committee quorum requires three distinct eligible attendees including labor union', function (array $votes, bool $passes, array $indices): void {
    $f = committeeFixture();
    $attendees = collect($votes)->map(fn ($vote, $index) => [
        'pool_member_id' => $f['members'][$indices[$index]]->id, 'vote' => $vote,
    ])->all();
    if ($passes) {
        app(CommitteeSessionService::class)->recordSession($f['case'], $attendees, null, 'Typed minutes', Outcome::Warning,
            DefenseAcceptance::Rejected, $f['hr'], $attendees[0]['pool_member_id']);
        expect($f['case']->fresh()->status)->toBe(CaseStatus::CommitteeSessionHeld);
    } else {
        expect(fn () => app(CommitteeSessionService::class)->recordSession($f['case'], $attendees, null, 'Typed minutes',
            Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $attendees[0]['pool_member_id']))->toThrow(TransitionBlocked::class);
    }
})->with([
    'two' => [['approve', 'approve'], false, [0, 1]],
    'three without union' => [['approve', 'approve', 'abstain'], false, [0, 1, 3]],
    'three with union' => [['approve', 'approve', 'reject'], true, [0, 1, 2]],
    'four with union' => [['approve', 'approve', 'approve', 'reject'], true, [0, 1, 2, 3]],
]);

test('strict majority can change on a re-vote while the session is held', function (): void {
    $f = committeeFixture();
    $service = app(CommitteeSessionService::class);
    $case = $service->recordSession($f['case'], sessionVotes($f['members'], ['approve', 'reject', 'abstain']),
        null, 'Initial vote', Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id);
    expect($case->session_majority_reached)->toBeFalse();
    $case = $service->recordSession($case, sessionVotes($f['members'], ['approve', 'approve', 'reject']),
        null, 'Re-vote', Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id);
    expect($case->session_majority_reached)->toBeTrue();
    expect($case->committee_session_notes)->toBe('Re-vote');
});

test('only more than half of those present sets the majority flag', function (array $votes, bool $majority): void {
    $f = committeeFixture();
    $case = app(CommitteeSessionService::class)->recordSession($f['case'], sessionVotes($f['members'], $votes),
        null, 'Vote count', Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id);
    expect($case->session_majority_reached)->toBe($majority);
})->with([
    'two to one' => [['approve', 'approve', 'reject'], true],
    'one one one' => [['approve', 'reject', 'abstain'], false],
    'one to two' => [['approve', 'reject', 'reject'], false],
]);

test('the mandate is selected on the issue date boundary', function (string $issueDate, bool $usesNew): void {
    $f = committeeFixture();
    $f['mandate']->update(['superseded_at' => '2026-06-19 00:00:00']);
    $new = CommitteeMandate::factory()->create(['effective_from' => '2026-06-19']);
    $newMembers = collect([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion])->map(fn ($role) => CommitteePoolMember::factory()->create(['mandate_id' => $new->id, 'role_type' => $role]));
    $f['case']->update(['defense_letter_issued_at' => $issueDate]);
    $chosen = $usesNew ? $newMembers : $f['members'];
    $case = app(CommitteeSessionService::class)->recordSession($f['case'], sessionVotes($chosen, ['approve', 'approve', 'reject']),
        null, 'Boundary session', Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $chosen[0]->id);
    expect($case->committee_mandate_id)->toBe($usesNew ? $new->id : $f['mandate']->id);
})->with([
    'day before' => ['2026-06-18 10:00:00', false],
    'on effective date' => ['2026-06-19 10:00:00', true],
    'day after' => ['2026-06-20 10:00:00', true],
]);

test('an employee-linked party is excluded and a name-only match requires HR clearance', function (): void {
    $f = committeeFixture();
    $linked = $f['members'][0];
    $linked->update(['employee_id' => $f['case']->employee_id]);
    expect(fn () => app(CommitteeSessionService::class)->recordSession($f['case'],
        sessionVotes($f['members'], ['approve', 'approve', 'approve']), null, 'Minutes',
        Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id))->toThrow(TransitionBlocked::class);
    $linked->update(['employee_id' => null, 'person_name' => $f['case']->employee->name]);
    expect(fn () => app(CommitteeSessionService::class)->recordSession($f['case'],
        sessionVotes($f['members'], ['approve', 'approve', 'approve']), null, 'Minutes',
        Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id))->toThrow(TransitionBlocked::class);
    app(ConflictClearance::class)->clear($f['case'], $linked, 'Different person, identity checked', $f['hr']);
    $case = app(CommitteeSessionService::class)->recordSession($f['case'],
        sessionVotes($f['members'], ['approve', 'approve', 'approve']), null, 'Minutes',
        Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id);
    expect($case->session_majority_reached)->toBeTrue();
});

test('employee-linked responsible and witness parties are hard exclusions', function (string $party): void {
    $f = committeeFixture();
    $member = $f['members'][0];
    $other = Employee::factory()->create(['branch_id' => $f['case']->branch_id]);
    $member->update(['employee_id' => $other->id]);
    if ($party === 'responsible') {
        $f['case']->update(['alleged_responsible_employee_id' => $other->id]);
    } else {
        $f['case']->witnesses()->create(['company_id' => $f['company']->id, 'employee_id' => $other->id, 'name' => $other->name]);
    }
    expect(fn () => app(CommitteeSessionService::class)->recordSession($f['case'],
        sessionVotes($f['members'], ['approve', 'approve', 'approve']), null, 'Minutes',
        Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id))->toThrow(TransitionBlocked::class);
})->with(['responsible', 'witness']);

test('a pool member assigned to another branch cannot attend', function (): void {
    $f = committeeFixture();
    $otherBranch = Branch::factory()->create();
    $f['members'][0]->update(['branch_id' => $otherBranch->id]);
    expect(fn () => app(CommitteeSessionService::class)->recordSession($f['case'],
        sessionVotes($f['members'], ['approve', 'approve', 'approve']), null, 'Minutes',
        Outcome::Warning, DefenseAcceptance::Rejected, $f['hr'], $f['members'][0]->id))->toThrow(TransitionBlocked::class);
});
