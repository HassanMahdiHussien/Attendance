<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('employee_id');
            $table->date('effective_from');
            $table->json('changes');
            $table->json('previous');
            $table->string('reason')->nullable();
            $table->string('source');
            $table->foreignId('changed_by')->nullable();
            $table->foreignId('employee_import_batch_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'effective_from', 'id'], 'employee_versions_as_of');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_import_batch_id'], 'employee_versions_batch_tenant_fk')->references(['company_id', 'id'])->on('employee_import_batches')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::table('employees')->orderBy('id')->chunkById(500, function ($employees): void {
            foreach ($employees as $employee) {
                $changes = [];
                foreach (['name', 'name_en', 'department', 'department_en', 'position', 'position_en', 'hire_date', 'branch_id', 'email', 'gender', 'work_pattern', 'rest_days', 'schedule_start', 'schedule_end', 'manager_employee_id', 'manager_name', 'fallback_notification_email'] as $field) {
                    $changes[$field] = $employee->{$field};
                }
                DB::table('employee_versions')->insert([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'effective_from' => $employee->hire_date ?: substr($employee->created_at, 0, 10),
                    'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                    'previous' => '{}',
                    'source' => 'initial',
                    'created_at' => now(),
                ]);
            }
        });

        DB::unprepared("CREATE TRIGGER `immutable_employee_versions_update` BEFORE UPDATE ON `employee_versions` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: employee_versions is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_employee_versions_delete` BEFORE DELETE ON `employee_versions` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: employee_versions is append-only'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_employee_versions_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_employee_versions_update`');
        Schema::dropIfExists('employee_versions');
    }
};
