<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlement_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->foreignId('request_id');
            $table->foreignId('request_day_id')->nullable();
            $table->foreignId('entitlement_id');
            $table->decimal('quantity', 4, 1);
            $table->json('explanation');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'leave_ent_cons_co_id_uq');
            $table->unique(['company_id', 'request_id', 'entitlement_id', 'run_id'], 'leave_ent_cons_run_uq');
            $table->index(['company_id', 'employee_id', 'superseded_by_run_id'], 'leave_ent_cons_current_idx');
            $table->index(['company_id', 'entitlement_id', 'superseded_by_run_id'], 'leave_ent_cons_ent_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'leave_ent_cons_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'], 'leave_ent_cons_request_fk')->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'request_day_id'], 'leave_ent_cons_day_fk')->references(['company_id', 'id'])->on('leave_request_days')->restrictOnDelete();
            $table->foreign(['company_id', 'entitlement_id'], 'leave_ent_cons_ent_fk')->references(['company_id', 'id'])->on('attendance_rest_day_entitlements')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'], 'leave_ent_cons_run_fk')->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'leave_ent_cons_super_fk')->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER `immutable_leave_entitlement_consumptions_update` BEFORE UPDATE ON `leave_entitlement_consumptions` FOR EACH ROW BEGIN IF NEW.`superseded_by_run_id` IS NULL OR OLD.`superseded_by_run_id` IS NOT NULL OR NOT (NEW.`id` <=> OLD.`id` AND NEW.`company_id` <=> OLD.`company_id` AND NEW.`employee_id` <=> OLD.`employee_id` AND NEW.`request_id` <=> OLD.`request_id` AND NEW.`request_day_id` <=> OLD.`request_day_id` AND NEW.`entitlement_id` <=> OLD.`entitlement_id` AND NEW.`quantity` <=> OLD.`quantity` AND NEW.`explanation` <=> OLD.`explanation` AND NEW.`run_id` <=> OLD.`run_id` AND NEW.`created_at` <=> OLD.`created_at`) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: leave_entitlement_consumptions'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_leave_entitlement_consumptions_delete` BEFORE DELETE ON `leave_entitlement_consumptions` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: leave_entitlement_consumptions'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_leave_entitlement_consumptions_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_leave_entitlement_consumptions_delete`');
        Schema::dropIfExists('leave_entitlement_consumptions');
    }
};
