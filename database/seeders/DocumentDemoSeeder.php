<?php

namespace Database\Seeders;

use App\Enums\CaseStatus as S;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateDocumentPdf;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\HandOffService;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Documents\LetterheadService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Demo seeders cannot run in production.');
        }
        // Demo data only: a re-seed recreates documents with the same ids, and the once-only PDF
        // protocol rightly refuses to overwrite a different file left by the previous seed.
        if (app()->environment(['local', 'testing'])) {
            Storage::disk('local')->deleteDirectory('documents');
            Storage::disk('local')->deleteDirectory('letterheads');
        }
        $company = Company::query()->firstOrFail();
        app(TenantContext::class)->runForCompany($company, function () use ($company): void {
            $admin = User::query()->where('company_id', $company->id)->where('primary_role', UserRole::CompanyAdmin->value)->firstOrFail();
            $this->letterheads($company, $admin);
            $members = [];
            foreach ([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion] as $role) {
                $member = CommitteePoolMember::query()->where('role_type', $role->value)->firstOrFail();
                $approver = User::factory()->create([
                    'company_id' => $company->id, 'employee_id' => $member->employee_id,
                    'primary_role' => UserRole::CommitteeMember,
                    'name' => $member->person_name, 'email' => 'demo.'.$role->value.'@example.test',
                ]);
                $member->update(['user_id' => $approver->id]);
                $members[] = [$member, $approver];
            }
            $employees = Employee::query()->orderBy('id')->get();
            // Case numbers need a transaction; it also keeps a failed demo case from leaving partial rows.
            DB::transaction(fn () => $this->case($company, $admin, $employees[10], $members, Outcome::Warning));
            DB::transaction(fn () => $this->case($company, $admin, $employees[11], $members, Outcome::RecommendationOnly));
        });
    }

    private function letterheads(Company $company, User $admin): void
    {
        $image = imagecreatetruecolor(140, 52);
        imagefill($image, 0, 0, imagecolorallocate($image, 154, 25, 34));
        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 22, 18, 'DEMO', $white);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = tempnam(sys_get_temp_dir(), 'demo-logo-');
        file_put_contents($path, $bytes);
        try {
            foreach (['ar', 'en'] as $language) {
                $logo = new UploadedFile($path, 'demo.png', 'image/png', null, true);
                app(LetterheadService::class)->saveVersion($company, $language, [
                    'name' => $language === 'en' ? 'Oasis Demo Company' : $company->name,
                    'address_lines' => $language === 'en' ? 'Demo Road, Hilla, Iraq' : 'شارع تجريبي، الحلة، العراق',
                    'phone' => '000 000 0000', 'email' => 'contact@example.test',
                    'footer_text' => 'CONFIDENTIAL', 'header_mode' => 'logo_text',
                ], $admin, $logo);
            }
        } finally {
            unlink($path);
        }
    }

    private function case(Company $company, User $admin, Employee $employee, array $members, Outcome $outcome): void
    {
        $case = DisciplinaryCase::create([
            'company_id' => $company->id, 'branch_id' => $employee->branch_id,
            'employee_id' => $employee->id, 'opened_by_user_id' => $admin->id,
            'violation_type' => $outcome === Outcome::Warning ? 'تأخر في تسليم سجل المخزن' : 'حاجة إلى تدريب على إجراءات المخزن',
            'violation_time' => now()->subDays(4), 'violation_place_freetext' => 'Demo warehouse',
        ]);
        $case = app(CaseOpening::class)->open($case, $admin);
        $workflow = app(CaseWorkflow::class);
        $defense = $this->draft($case, 'defense_letter', [
            'ar' => 'تطلب اللجنة بيان الموظف بشأن سجل عمل لم يكتمل في الموعد المحدد.',
            'en' => 'The committee requests the employee’s account of a work record that was not completed on time.',
        ], $admin);
        $case = $workflow->transition($case, S::DefenseLetterCitationCheck, $admin, 'Human draft');
        $case = $workflow->transition($case, S::DefenseLetterReadyForReview, $admin, 'Citation check');
        $case = $workflow->transition($case, S::DefenseLetterConfirmed, $admin, 'HR confirmation');
        $this->issue($defense, $admin);
        $case = $case->fresh();
        app(HandOffService::class)->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::Signed,
            ['signed_at' => now()], $admin);
        $attendees = array_map(fn (array $pair): array => ['pool_member_id' => $pair[0]->id, 'vote' => 'approve'], $members);
        $case = app(CommitteeSessionService::class)->recordSession($case->fresh(), $attendees, null,
            'The committee reviewed the employee statement and the work record.', $outcome, DefenseAcceptance::AcceptedPartially, $admin,
            $members[0][0]->id);
        $recommendations = Document::create([
            'company_id' => $company->id, 'case_id' => $case->id, 'document_type' => 'recommendations',
            'version' => 1, 'drafted_by' => 'human', 'created_by' => $admin->id,
        ]);
        app(RecommendationsEditor::class)->update($recommendations, [
            'ar' => ['body' => 'راجعت اللجنة السجل وإفادة الموظف.', 'secondary_body' => $outcome === Outcome::Warning ? 'أوصت اللجنة بإنذار خطي.' : 'أوصت اللجنة بتدريب إضافي دون عقوبة.'],
            'en' => ['body' => 'The committee reviewed the record and employee statement.', 'secondary_body' => $outcome === Outcome::Warning ? 'The committee recommended a written warning.' : 'The committee recommended additional training without a penalty.'],
        ], $admin);
        $case = $workflow->transition($case, S::RecommendationsCitationCheck, $admin, 'Human draft');
        $case = $workflow->transition($case, S::RecommendationsAwaitingApprovals, $admin, 'Citation check');
        foreach ($members as [, $approver]) {
            app(DocumentApprovalService::class)->approve($recommendations, $approver);
        }
        $this->issue($recommendations, $admin);
        $case = $workflow->transition($case->fresh(), S::SignedRecommendationsUploaded, $admin, 'Signed paper copy received');
        if ($outcome === Outcome::RecommendationOnly) {
            $workflow->transition($case, S::ArchivedFinal, $admin, 'Training recommendation only');

            return;
        }
        $case = $workflow->transition($case, S::DecisionLetterCitationCheck, $admin, 'Human decision draft');
        $decision = $this->draft($case, 'decision_letter', [
            'ar' => 'بعد مراجعة الإفادة والتوصيات، تقرر توجيه إنذار خطي بسبب عدم إكمال السجل في الموعد.',
            'en' => 'After reviewing the statement and recommendations, a written warning was decided for the late record.',
        ], $admin, 'warning');
        $workflow->transition($case, S::DecisionLetterReadyForReview, $admin, 'Citation check');
        $this->issue($decision, $admin);
    }

    private function draft(DisciplinaryCase $case, string $type, array $bodies, User $admin, ?string $penalty = null): Document
    {
        $document = Document::create([
            'company_id' => $case->company_id, 'case_id' => $case->id, 'document_type' => $type,
            'version' => 1, 'drafted_by' => 'human', 'created_by' => $admin->id,
            'penalty_type' => $penalty,
        ]);
        foreach ($bodies as $language => $body) {
            $document->contents()->create(['company_id' => $case->company_id, 'language_code' => $language,
                'body' => $body, 'is_authoritative' => $language === 'ar']);
        }

        return $document;
    }

    private function issue(Document $document, User $admin): void
    {
        $issued = app(CaseDocumentIssuance::class)->issue($document, $admin);
        GenerateDocumentPdf::dispatchSync($issued->id, $issued->company_id);
    }
}
