<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Leave\DescriptionReading;
use App\Services\AuditLogger;
use App\Services\Leave\LeaveDescriptionEffects;
use App\Services\Leave\LeaveDescriptionReader;
use App\Services\Leave\ReconciliationTriggers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLeaveNotePeriods extends Command
{
    protected $signature = 'attendance:leave-notes-refresh-periods {--company=} {--dry-run}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('messages.leave_descriptions.refresh_description'));
    }

    public function handle(LeaveDescriptionReader $reader, LeaveDescriptionEffects $effects,
        AuditLogger $audit, ReconciliationTriggers $triggers): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if ($companyId === false || $companyId === null) {
            $this->error(__('messages.leave_descriptions.refresh_company_required'));

            return self::FAILURE;
        }

        $company = Company::findOrFail($companyId);
        $changed = 0;
        $applying = 0;
        $employeeIds = [];
        app(TenantContext::class)->runForCompany($company, function () use ($company, $reader, $effects, $audit,
            &$changed, &$applying, &$employeeIds): void {
            DescriptionReading::query()->with(['request.currentVersion', 'request.leaveType'])
                ->where('company_id', $company->id)->where('category', 'hourly_settlement')
                ->orderBy('id')->chunkById(500, function ($readings) use ($reader, $effects, $audit,
                    &$changed, &$applying, &$employeeIds): void {
                    foreach ($readings as $reading) {
                        $request = $reading->request;
                        if ($request === null) {
                            continue;
                        }
                        $beforeApplied = $effects->applies($request, $reading, 'hourly_settlement');
                        $before = [
                            'settled_from' => $reading->settled_from?->toDateString(),
                            'settled_to' => $reading->settled_to?->toDateString(),
                            'confidence' => $reading->confidence,
                        ];
                        $after = $reader->hourlyPeriod((string) ($request->currentVersion?->values['description'] ?? ''),
                            $request->start_date?->toDateString(), $reading->confidence);
                        if ($before !== $after) {
                            $changed++;
                            if (! $this->option('dry-run')) {
                                DB::transaction(function () use ($reading, $after, $audit, $before, $request): void {
                                    $reading->update($after);
                                    $audit->log('leave.description.period_refreshed', $reading, [
                                        'before' => $before, 'after' => $after, 'request_id' => $request->id,
                                    ]);
                                });
                            } else {
                                $reading->forceFill($after);
                            }
                        }
                        $afterApplied = $effects->applies($request, $reading, 'hourly_settlement');
                        if ($afterApplied) {
                            $applying++;
                        }
                        if ((($beforeApplied && $before !== $after) || $afterApplied) && $request->employee_id !== null) {
                            $employeeIds[$request->employee_id] = $request->employee_id;
                        }
                    }
                });
        });

        if (! $this->option('dry-run')) {
            // Marking months dirty and queueing the replay are tenant-scoped; a console run has no company of its own.
            app(TenantContext::class)->runForCompany($company,
                fn () => $triggers->dispatch($company->id, array_values($employeeIds), 'description'));
        }
        $this->line(__('messages.leave_descriptions.refresh_counts', [
            'changed' => $changed, 'applying' => $applying, 'employees' => count($employeeIds),
        ]));

        return self::SUCCESS;
    }
}
