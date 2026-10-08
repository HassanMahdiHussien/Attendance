<?php

namespace App\Services\Statistics;

use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportExporter
{
    public function __construct(private ReportBuilder $builder, private AuditLogger $audit) {}

    public function request(User $viewer, string $type, array $parameters, string $format): ReportExport
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 422);
        $parameters = $this->builder->validate($viewer, $type, $parameters);
        $parameters = $this->builder->snapshotVisibility($viewer, $type, $parameters);
        $context = app(TenantContext::class);
        $create = function () use ($viewer, $type, $parameters, $format): ReportExport {
            $export = ReportExport::create(['company_id' => $parameters['company_id'],
                'requested_by' => $viewer->id, 'report_type' => $type, 'parameters' => $parameters,
                'format' => $format, 'status' => 'queued']);
            $this->audit->log('report_export.requested', $export, ['report_type' => $type, 'format' => $format], $viewer);

            return $export;
        };
        $transaction = fn () => DB::transaction($create);
        $export = $parameters['company_id'] === null ? $context->runAcrossAllCompanies($transaction)
            : $context->runForCompany($parameters['company_id'], $transaction);
        GenerateReportExport::dispatch($export->id, $export->company_id);

        return $export;
    }

    /**
     * Revokes every ready export of a user who lost access (e.g. deactivated): the files are deleted at once so a
     * stale export can never be downloaded later, even by the Platform Admin route.
     */
    public function revokeAllFor(User $user): void
    {
        $context = app(TenantContext::class);
        $context->runAcrossAllCompanies(function () use ($user): void {
            foreach (ReportExport::query()->where('requested_by', $user->id)->where('status', 'ready')->get() as $export) {
                if ($export->file_path === "report-exports/{$export->id}.{$export->format}") {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->forceFill(['status' => 'revoked', 'file_path' => null, 'file_sha256' => null])->save();
                $this->audit->log('report_export.revoked', $export, ['reason' => 'account_deactivated']);
            }
        });
    }
}
