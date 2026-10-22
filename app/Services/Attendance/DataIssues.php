<?php

namespace App\Services\Attendance;

use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DataIssues
{
    public function __construct(private AuditLogger $audit) {}

    public function open(Company $company, string $type, string $subjectType, string $subjectKey, ?int $batchId = null, array $details = []): DataIssue
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $openKey = $type.'|'.$subjectType.'|'.$subjectKey;
        $inserted = DB::table('data_issues')->insertOrIgnore([
            'company_id' => $company->id, 'type' => $type, 'subject_type' => $subjectType,
            'subject_key' => $subjectKey, 'open_key' => $openKey, 'batch_id' => $batchId,
            'status' => 'open', 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $issue = DataIssue::query()->where('company_id', $company->id)->where('open_key', $openKey)->lockForUpdate()->firstOrFail();
        if ($inserted) {
            $this->audit->log('attendance.issue.opened', $issue, ['type' => $type, 'subject_key' => $subjectKey]);
        }

        return $issue;
    }

    public function resolve(DataIssue $issue, User $actor, string $note): void
    {
        $this->close($issue, $actor, $note, 'resolved');
    }

    public function dismiss(DataIssue $issue, User $actor, string $note): void
    {
        $this->close($issue, $actor, $note, 'dismissed');
    }

    private function close(DataIssue $issue, User $actor, string $note, string $status): void
    {
        DB::transaction(function () use ($issue, $actor, $note, $status): void {
            $issue = DataIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($issue->status !== 'open') {
                return;
            }
            $issue->update(['status' => $status, 'open_key' => null, 'note' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            $this->audit->log('attendance.issue.'.$status, $issue, [
                'type' => $issue->type, 'subject_key' => $issue->subject_key,
                'before' => 'open', 'after' => $status,
            ], $actor);
        });
    }
}
