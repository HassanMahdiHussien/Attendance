<?php

use App\Exceptions\TenantMismatch;
use App\Models\AppealTracking;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CaseAlert;
use App\Models\CaseConflictClearance;
use App\Models\CaseEvent;
use App\Models\CaseLink;
use App\Models\CaseWitness;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\CompanySequence;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentContent;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Location;
use App\Models\ReportWitness;
use App\Models\Signature;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;

dataset('tenant models', [
    Branch::class, Location::class, Employee::class, BranchUser::class,
    CommitteeMandate::class, CommitteePoolMember::class, ViolationReport::class,
    ReportWitness::class, DisciplinaryCase::class, CaseWitness::class,
    CaseEvent::class, CaseLink::class, CommitteeSessionAttendance::class,
    AppealTracking::class, Attachment::class, Document::class,
    DocumentContent::class, DocumentApproval::class, Signature::class,
    CompanySequence::class, CaseAlert::class, CaseConflictClearance::class,
    CompanyLetterhead::class, DocumentTemplate::class,
]);

test('every tenant model isolates reads and builder writes and stamps creation', function (string $model): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);

    $own = $context->runForCompany($a, fn () => $model::factory()->create());
    $other = $context->runForCompany($b, fn () => $model::factory()->create());

    $context->setCompany($a);
    $query = $model::query();
    if ($model === CompanyLetterhead::class) {
        $query->where('language_code', 'ku');
    }
    expect($query->pluck('id')->all())->toBe([$own->getKey()]);
    expect($model::find($other->getKey()))->toBeNull();
    expect($model === CompanyLetterhead::class ? $model::query()->where('language_code', 'ku')->count() : $model::count())->toBe(1);
    expect($model::query()->whereKey($other->getKey())->update(['company_id' => $a->id]))->toBe(0);
    expect($model::query()->whereKey($other->getKey())->delete())->toBe(0);

    $attributes = $model === CompanySequence::class ? ['sequence_key' => 'employee_code'] : [];
    $created = $model::factory()->create($attributes + ['company_id' => null]);
    expect((int) $created->company_id)->toBe($a->id);
    expect(fn () => $model::factory()->create(['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
})->with('tenant models');
