<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('overtime_min_minutes')->default(30);
            $table->unsignedSmallInteger('overtime_step_minutes')->default(15);
            $table->unsignedSmallInteger('rest_day_half_minutes')->default(240);
            $table->unsignedSmallInteger('rest_day_full_minutes')->default(480);
            $table->json('compensatory_rest_days')->default(new Expression('(JSON_OBJECT("office", JSON_ARRAY(5), "shift", JSON_ARRAY(5), "security", "individual"))'));
        });

        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->unsignedInteger('overtime_minutes')->nullable();
            $table->unsignedInteger('early_arrival_minutes')->nullable();
            $table->string('overtime_basis')->nullable();
        });

        Schema::create('attendance_rest_day_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->date('work_date');
            $table->foreignId('source_day_id');
            $table->decimal('quantity', 3, 1);
            $table->unsignedInteger('worked_minutes');
            $table->string('basis');
            $table->boolean('holiday')->default(false);
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_rest_ent_co_id_uq');
            $table->unique(['company_id', 'source_day_id'], 'att_rest_ent_source_uq');
            $table->index(['company_id', 'employee_id', 'work_date', 'superseded_by_run_id'], 'att_rest_ent_current_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'att_rest_ent_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'source_day_id'], 'att_rest_ent_day_fk')->references(['company_id', 'id'])->on('attendance_days')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'], 'att_rest_ent_run_fk')->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'att_rest_ent_super_fk')->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER `immutable_attendance_rest_day_entitlements_update` BEFORE UPDATE ON `attendance_rest_day_entitlements` FOR EACH ROW BEGIN IF NEW.`superseded_by_run_id` IS NULL OR OLD.`superseded_by_run_id` IS NOT NULL OR NOT (NEW.`id` <=> OLD.`id` AND NEW.`company_id` <=> OLD.`company_id` AND NEW.`employee_id` <=> OLD.`employee_id` AND NEW.`work_date` <=> OLD.`work_date` AND NEW.`source_day_id` <=> OLD.`source_day_id` AND NEW.`quantity` <=> OLD.`quantity` AND NEW.`worked_minutes` <=> OLD.`worked_minutes` AND NEW.`basis` <=> OLD.`basis` AND NEW.`holiday` <=> OLD.`holiday` AND NEW.`run_id` <=> OLD.`run_id` AND NEW.`created_at` <=> OLD.`created_at`) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_rest_day_entitlements'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_attendance_rest_day_entitlements_delete` BEFORE DELETE ON `attendance_rest_day_entitlements` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_rest_day_entitlements'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_rest_day_entitlements_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_rest_day_entitlements_delete`');
        Schema::dropIfExists('attendance_rest_day_entitlements');
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropColumn(['overtime_minutes', 'early_arrival_minutes', 'overtime_basis']);
        });
        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->dropColumn(['overtime_min_minutes', 'overtime_step_minutes', 'rest_day_half_minutes', 'rest_day_full_minutes', 'compensatory_rest_days']);
        });
    }
};
