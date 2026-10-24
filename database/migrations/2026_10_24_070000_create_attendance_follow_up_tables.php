<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->date('work_date');
            $table->foreignId('day_id')->nullable();
            $table->string('state', 32);
            $table->json('items');
            $table->json('routes');
            $table->json('request_ids');
            $table->string('closure_reason')->nullable();
            $table->json('closure_source')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('manual_closed_items')->nullable();
            $table->foreignId('manual_closed_by')->nullable();
            $table->timestamp('manual_closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_cases_co_id_uq');
            $table->unique(['company_id', 'employee_id', 'work_date'], 'att_cases_employee_date_uq');
            $table->index(['company_id', 'state', 'work_date'], 'att_cases_state_date_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'att_cases_employee_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'day_id'], 'att_cases_day_fk')
                ->references(['company_id', 'id'])->on('attendance_days')->restrictOnDelete();
            $table->foreign('manual_closed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('case_id');
            $table->string('event', 40);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('source', 80);
            $table->foreignId('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_case_events_co_id_uq');
            $table->index(['company_id', 'case_id', 'id'], 'att_case_events_case_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'], 'att_case_events_case_fk')
                ->references(['company_id', 'id'])->on('attendance_cases')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_approver_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('approver_key');
            $table->string('approver_text');
            $table->foreignId('employee_id');
            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_approver_map_co_id_uq');
            $table->unique(['company_id', 'approver_key'], 'att_approver_map_key_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'att_approver_map_employee_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique('att_notif_settings_company_uq');
            $table->string('mode', 16)->default('review');
            $table->time('build_time')->default('09:00:00');
            $table->unsignedSmallInteger('reminder_interval_days')->default(2);
            $table->unsignedSmallInteger('reminder_max')->default(3);
            $table->string('language', 2)->default('en');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        foreach (['update', 'delete'] as $operation) {
            DB::unprepared("CREATE TRIGGER `immutable_attendance_case_events_{$operation}` BEFORE ".strtoupper($operation)." ON `attendance_case_events` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_case_events is append-only'; END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_case_events_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_case_events_update`');
        Schema::dropIfExists('attendance_notification_settings');
        Schema::dropIfExists('attendance_approver_mappings');
        Schema::dropIfExists('attendance_case_events');
        Schema::dropIfExists('attendance_cases');
    }
};
