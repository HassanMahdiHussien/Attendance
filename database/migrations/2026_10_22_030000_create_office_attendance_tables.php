<?php

use App\Services\Attendance\OfficePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->date('effective_from');
            $table->time('office_start');
            $table->unsignedSmallInteger('flex_minutes');
            $table->unsignedSmallInteger('workday_minutes');
            $table->unsignedSmallInteger('min_presence_minutes');
            $table->unsignedSmallInteger('close_grace_minutes');
            $table->boolean('exits_count_toward_balance');
            $table->json('office_rest_days');
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'version'], 'att_policies_company_version_uq');
            $table->index(['company_id', 'effective_from', 'version'], 'att_policies_effective_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_manual_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->dateTime('punched_at');
            $table->string('role');
            $table->text('reason');
            $table->foreignId('created_by');
            $table->foreignId('reverses_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'reverses_id'], 'att_manual_one_reversal_uq');
            $table->index(['company_id', 'employee_id', 'punched_at'], 'att_manual_employee_time_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'reverses_id'], 'att_manual_reversal_fk')->references(['company_id', 'id'])->on('attendance_manual_punches')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_day_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->date('work_date');
            $table->string('decision');
            $table->text('reason');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('created_by');
            $table->foreignId('manual_punch_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'work_date'], 'att_decisions_employee_day_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'manual_punch_id'], 'att_decisions_manual_fk')->references(['company_id', 'id'])->on('attendance_manual_punches')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('attendance_processing_runs', function (Blueprint $table): void {
            $table->json('scope')->nullable();
        });

        Schema::create('attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->foreignId('branch_id')->nullable();
            $table->date('work_date');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->string('status');
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedInteger('elapsed_minutes')->nullable();
            $table->unsignedInteger('presence_minutes')->nullable();
            $table->unsignedInteger('late_minutes')->nullable();
            $table->unsignedInteger('early_minutes')->nullable();
            $table->unsignedInteger('exit_minutes')->nullable();
            $table->unsignedInteger('short_minutes')->nullable();
            $table->unsignedInteger('balance_minutes')->nullable();
            $table->dateTime('required_leave_at')->nullable();
            $table->json('intervals');
            $table->json('exits');
            $table->foreignId('policy_id')->nullable();
            $table->json('inputs');
            $table->boolean('reviewed')->default(false);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'work_date', 'superseded_by_run_id'], 'att_days_employee_current_idx');
            $table->index(['company_id', 'branch_id', 'work_date', 'superseded_by_run_id'], 'att_days_branch_current_idx');
            $table->index(['company_id', 'work_date', 'status', 'superseded_by_run_id'], 'att_days_status_current_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'att_days_superseded_fk')->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'policy_id'])->references(['company_id', 'id'])->on('attendance_policies')->restrictOnDelete();
        });

        // Existing tenants need a baseline without requiring a production seeder run. It starts in 2000 so punches
        // imported for months before the company was created are still assessed.
        foreach (DB::table('companies')->select('id')->orderBy('id')->cursor() as $company) {
            DB::table('attendance_policies')->insert([
                'company_id' => $company->id,
                'effective_from' => OfficePolicy::DEFAULT_EFFECTIVE_FROM,
                'office_start' => '08:00:00',
                'flex_minutes' => 60,
                'workday_minutes' => 540,
                'min_presence_minutes' => 480,
                'close_grace_minutes' => 120,
                'exits_count_toward_balance' => false,
                'office_rest_days' => '[5,6]',
                'version' => 1,
                'created_at' => now(),
            ]);
        }

        foreach (['attendance_policies', 'attendance_manual_punches', 'attendance_day_decisions'] as $table) {
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
        }
    }

    public function down(): void
    {
        foreach (['attendance_day_decisions', 'attendance_manual_punches', 'attendance_policies'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_update`");
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_delete`");
        }

        Schema::dropIfExists('attendance_days');
        Schema::table('attendance_processing_runs', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
        Schema::dropIfExists('attendance_day_decisions');
        Schema::dropIfExists('attendance_manual_punches');
        Schema::dropIfExists('attendance_policies');
    }
};
