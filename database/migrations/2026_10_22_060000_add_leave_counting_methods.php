<?php

use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Company;
use App\Services\Leave\UnknownEmployeeIssues;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_settings', function (Blueprint $table): void {
            $table->json('leave_calendar_rest_days')->nullable();
        });

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('counting_method')->nullable();
            $table->index(['company_id', 'owner_code', 'employee_id'], 'leave_requests_owner_link_idx');
        });

        DB::table('leave_settings')->whereNull('leave_calendar_rest_days')
            ->update(['leave_calendar_rest_days' => json_encode([5], JSON_THROW_ON_ERROR)]);

        // Existing imports need a replay so their current distributions reflect the newly accepted counts.
        $companies = DB::table('leave_requests')->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            $company = Company::findOrFail($companyId);
            app(TenantContext::class)->runForCompany($company, function () use ($company): void {
                DB::table('leave_requests')->where('company_id', $company->id)->distinct()
                    ->orderBy('owner_code')->pluck('owner_code')
                    ->each(fn (string $code) => app(UnknownEmployeeIssues::class)->refresh($company, $code));
            });
            $requestEmployees = DB::table('leave_requests')->where('company_id', $companyId)
                ->whereNotNull('employee_id')->select('employee_id');
            DB::table('employees')->where('company_id', $companyId)->whereIn('id', $requestEmployees)
                ->select('id')->orderBy('id')->chunkById(100, function ($rows) use ($companyId): void {
                    ReconcileLeaveJob::dispatch((int) $companyId, $rows->pluck('id')->all(), 'counting_method_upgrade')->afterCommit();
                });
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropIndex('leave_requests_owner_link_idx');
            $table->dropColumn('counting_method');
        });

        Schema::table('leave_settings', function (Blueprint $table): void {
            $table->dropColumn('leave_calendar_rest_days');
        });
    }
};
