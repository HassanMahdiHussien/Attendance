<?php

namespace App\Services\Attendance\Reporting;

use App\Jobs\Attendance\GenerateAttendanceExportJob;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceExports
{
    public function request(Company $company, User $actor, string $kind, array $input, string $locale): AttendanceExport
    {
        Gate::forUser($actor)->authorize('create', [AttendanceExport::class, $company]);
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        if (! in_array($kind, ExportKinds::ALL, true) || ! in_array($locale, ['en', 'ar'], true)) {
            throw ValidationException::withMessages(['export' => __('messages.export_ui.invalid')]);
        }
        $filters = ReportFilters::fromArray($company, $input);
        if (in_array($kind, ExportKinds::EMPLOYEE_ONLY, true) && count($filters->employeeIds) !== 1) {
            throw ValidationException::withMessages(['export' => __('messages.export_ui.one_employee')]);
        }

        return DB::transaction(function () use ($company, $actor, $kind, $locale, $filters): AttendanceExport {
            $extension = $kind === 'statement_pdf' ? 'pdf' : 'xlsx';
            $export = AttendanceExport::query()->create([
                'company_id' => $company->id, 'requested_by' => $actor->id, 'kind' => $kind,
                'filters' => $filters->toArray(), 'locale' => $locale,
                'file_name' => $kind.'-'.$filters->from.'-'.$filters->to.'.'.$extension,
            ]);
            $this->event($export, 'queued');
            app(AuditLogger::class)->log('attendance.export.requested', $export, ['kind' => $kind, 'from' => $filters->from, 'to' => $filters->to], $actor);
            GenerateAttendanceExportJob::dispatch($export->id)->afterCommit();

            return $export;
        });
    }

    public function retry(AttendanceExport $export, User $actor): void
    {
        Gate::forUser($actor)->authorize('retry', $export);
        abort_unless(app(TenantContext::class)->companyId() === $export->company_id, 403);
        DB::transaction(function () use ($export, $actor): void {
            $locked = AttendanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->events()->latest('sequence')->value('state') === 'failed', 403);
            $this->event($locked, 'queued');
            app(AuditLogger::class)->log('attendance.export.retried', $locked, ['kind' => $locked->kind], $actor);
            GenerateAttendanceExportJob::dispatch($locked->id)->afterCommit();
        });
    }

    public function event(AttendanceExport $export, string $state, ?string $errorCode = null): AttendanceExportEvent
    {
        return AttendanceExportEvent::query()->create([
            'company_id' => $export->company_id, 'export_id' => $export->id,
            'sequence' => (int) $export->events()->max('sequence') + 1,
            'state' => $state, 'error_code' => $errorCode,
        ]);
    }

    public function cleanup(): int
    {
        $count = 0;
        AttendanceExport::withoutGlobalScopes()->whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays(30))->orderBy('id')->chunkById(100, function ($exports) use (&$count): void {
                foreach ($exports as $export) {
                    $company = Company::findOrFail($export->company_id);
                    app(TenantContext::class)->runForCompany($company, function () use ($export, &$count): void {
                        DB::transaction(function () use ($export, &$count): void {
                            $locked = AttendanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                            if ($locked->file_path === null || $locked->events()->latest('sequence')->value('state') !== 'completed') {
                                return;
                            }
                            if (Storage::disk('local')->exists($locked->file_path)
                                && ! Storage::disk('local')->delete($locked->file_path)) {
                                return;
                            }
                            $locked->update(['file_path' => null]);
                            $this->event($locked, 'file_deleted');
                            app(AuditLogger::class)->log('attendance.export.file_deleted', $locked, ['export_id' => $locked->id]);
                            $count++;
                        });
                    });
                }
            });

        return $count;
    }
}
