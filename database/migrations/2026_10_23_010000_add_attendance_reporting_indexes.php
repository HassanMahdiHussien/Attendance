<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_allocations', function (Blueprint $table): void {
            $table->index(['company_id', 'target_day_id', 'superseded_by_run_id'], 'leave_alloc_target_current_idx');
            $table->index(['company_id', 'source_credit_id', 'superseded_by_run_id'], 'leave_alloc_credit_current_idx');
        });
        Schema::table('leave_request_days', function (Blueprint $table): void {
            $table->index(['company_id', 'work_date', 'superseded_by_run_id', 'employee_id'], 'leave_days_report_period_idx');
        });
        Schema::table('attendance_meals', function (Blueprint $table): void {
            $table->index(['company_id', 'employee_id', 'meal_date', 'superseded_by_run_id'], 'att_meals_employee_period_idx');
        });
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->index(['company_id', 'subject_type', 'subject_key'], 'att_issues_report_subject_idx');
            $table->index(['company_id', 'subject_key'], 'att_issues_report_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->dropIndex('att_issues_report_key_idx');
            $table->dropIndex('att_issues_report_subject_idx');
        });
        // MySQL drops a foreign key's implicit index once a wider index starts with the same columns, so the
        // wider index cannot be dropped until a plain backing index exists again.
        $this->keepForeignKeyIndex('attendance_meals', ['company_id', 'employee_id'], 'att_meals_co_emp_fk_idx');
        Schema::table('attendance_meals', function (Blueprint $table): void {
            $table->dropIndex('att_meals_employee_period_idx');
        });
        Schema::table('leave_request_days', function (Blueprint $table): void {
            $table->dropIndex('leave_days_report_period_idx');
        });
        $this->keepForeignKeyIndex('leave_allocations', ['company_id', 'source_credit_id'], 'leave_alloc_co_credit_fk_idx');
        $this->keepForeignKeyIndex('leave_allocations', ['company_id', 'target_day_id'], 'leave_alloc_co_target_fk_idx');
        Schema::table('leave_allocations', function (Blueprint $table): void {
            $table->dropIndex('leave_alloc_credit_current_idx');
            $table->dropIndex('leave_alloc_target_current_idx');
        });
    }

    private function keepForeignKeyIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasIndex($table, $columns)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }
};
