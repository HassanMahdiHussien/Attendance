<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('department')->nullable()->change();
            $table->string('position')->nullable()->change();
            $table->string('work_pattern')->nullable();
            $table->json('rest_days')->nullable();
            $table->time('schedule_start')->nullable();
            $table->time('schedule_end')->nullable();
            $table->foreignId('manager_employee_id')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('fallback_notification_email')->nullable();
            $table->date('last_working_day')->nullable();
            $table->string('exit_status')->default('none');
            $table->foreignId('exit_review_batch_id')->nullable();
            $table->foreign(['company_id', 'manager_employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'exit_review_batch_id'])->references(['company_id', 'id'])->on('employee_import_batches')->restrictOnDelete();
        });
        DB::table('employees')->where('department', '')->update(['department' => null]);
        DB::table('employees')->where('position', '')->update(['position' => null]);
        foreach (['name_en', 'department_en', 'position_en', 'email'] as $column) {
            DB::table('employees')->where($column, '')->update([$column => null]);
        }
        DB::statement("ALTER TABLE `employees` ADD CONSTRAINT `employees_work_pattern_check` CHECK (`work_pattern` IS NULL OR `work_pattern` IN ('office', 'shift', 'security'))");
        DB::statement("ALTER TABLE `employees` ADD CONSTRAINT `employees_exit_status_check` CHECK (`exit_status` IN ('none', 'pending_review', 'confirmed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `employees` DROP CHECK `employees_exit_status_check`');
        DB::statement('ALTER TABLE `employees` DROP CHECK `employees_work_pattern_check`');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'exit_review_batch_id']);
            $table->dropForeign(['company_id', 'manager_employee_id']);
            $table->dropColumn(['work_pattern', 'rest_days', 'schedule_start', 'schedule_end', 'manager_employee_id', 'manager_name', 'fallback_notification_email', 'last_working_day', 'exit_status', 'exit_review_batch_id']);
        });
        DB::table('employees')->whereNull('department')->update(['department' => '']);
        DB::table('employees')->whereNull('position')->update(['position' => '']);
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('department')->nullable(false)->change();
            $table->string('position')->nullable(false)->change();
        });
    }
};
