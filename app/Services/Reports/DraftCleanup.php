<?php

namespace App\Services\Reports;

use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes drafts nobody finished: reporter chat sessions that were never sent and HR upload forms (whose files
 * were copied onto the case when it was saved), together with their files. These hold personal data with no
 * owner; evidence on a sent report or a case is never touched (the database refuses it).
 */
class DraftCleanup
{
    public function run(): array
    {
        $cutoff = now()->subDay();
        $sessions = DB::table('report_assistant_sessions')->whereNull('violation_report_id')
            ->where('created_at', '<', $cutoff)->get(['id', 'company_id']);
        $intakes = DB::table('case_file_intakes')->where('created_at', '<', $cutoff)->get(['id', 'company_id']);
        $files = DB::table('attachments')
            ->where(fn ($query) => $query->where('attachable_type', 'report_assistant_session')
                ->whereIn('attachable_id', $sessions->pluck('id')))
            ->orWhere(fn ($query) => $query->where('attachable_type', 'case_file_intake')
                ->whereIn('attachable_id', $intakes->pluck('id')))
            ->get(['id', 'company_id', 'disk', 'path']);

        DB::transaction(function () use ($files, $sessions, $intakes): void {
            DB::table('attachment_texts')->whereIn('attachment_id', $files->pluck('id'))->delete();
            DB::table('attachments')->whereIn('id', $files->pluck('id'))->delete();
            DB::table('report_assistant_sessions')->whereIn('id', $sessions->pluck('id'))->delete();
            DB::table('case_file_intakes')->whereIn('id', $intakes->pluck('id'))->delete();
        });
        // Rows go first so a file is never left referenced; a file the disk fails to delete is only orphaned bytes.
        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
        foreach ($files->groupBy('company_id') as $companyId => $companyFiles) {
            app(TenantContext::class)->runForCompany((int) $companyId, fn () => app(AuditLogger::class)
                ->log('drafts.files_removed', null, [
                    'files' => $companyFiles->count(),
                    'report_drafts' => $sessions->where('company_id', $companyId)->count(),
                    'case_uploads' => $intakes->where('company_id', $companyId)->count(),
                ]));
        }

        return ['files' => $files->count(), 'report_drafts' => $sessions->count(), 'case_uploads' => $intakes->count()];
    }
}
