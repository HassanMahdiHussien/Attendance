<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ReportExport;
use App\Services\AuditLogger;
use App\Services\Statistics\ReportBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReportExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $export, ReportBuilder $builder, AuditLogger $audit)
    {
        $viewer = $request->user();
        $context = app(TenantContext::class);
        $row = $context->runAcrossAllCompanies(fn () => ReportExport::findOrFail($export));
        abort_unless((int) $row->requested_by === (int) $viewer->id || $viewer->primary_role === UserRole::PlatformAdmin, 404);
        $work = function () use ($row, $viewer, $builder, $audit) {
            try {
                $builder->validate($viewer, $row->report_type, $row->parameters);
                if ($viewer->primary_role !== UserRole::PlatformAdmin) {
                    $builder->assertCurrentVisibility($viewer, $row->parameters);
                }
            } catch (Throwable $error) {
                $this->revoke($row);
                abort(404);
            }
            abort_unless($row->status === 'ready' && $row->expires_at?->isFuture(), 404);
            $path = $row->file_path;
            $disk = Storage::disk('local');
            abort_unless($path === "report-exports/{$row->id}.{$row->format}" && $disk->exists($path)
                && hash_file('sha256', $disk->path($path)) === $row->file_sha256, 404);
            $audit->log('report_export.downloaded', $row, [], $viewer);

            return $disk->download($path, basename($path), ['Content-Type' => $row->format === 'pdf'
                ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        };

        return $row->company_id === null ? $context->runAcrossAllCompanies($work)
            : $context->runForCompany($row->company_id, $work);
    }

    private function revoke(ReportExport $row): void
    {
        if ($row->status !== 'ready') {
            return;
        }
        if ($row->file_path === "report-exports/{$row->id}.{$row->format}") {
            Storage::disk('local')->delete($row->file_path);
        }
        $row->forceFill(['status' => 'revoked', 'file_path' => null, 'file_sha256' => null])->save();
    }
}
