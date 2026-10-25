<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_notification_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('run_key', 80);
            $table->timestamp('cut_off');
            $table->string('mode', 16);
            $table->string('status', 24);
            $table->foreignId('built_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_not_batches_co_id_uq');
            $table->unique(['company_id', 'run_key'], 'att_not_batches_run_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('built_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_notification_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->uuid('uuid');
            $table->string('recipient_kind', 16);
            $table->foreignId('recipient_employee_id')->nullable();
            $table->string('address');
            $table->string('language', 2);
            $table->string('status', 24);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_not_messages_co_id_uq');
            $table->unique(['company_id', 'uuid'], 'att_not_messages_uuid_uq');
            $table->unique(['batch_id', 'address'], 'att_not_messages_batch_addr_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'], 'att_not_messages_batch_fk')
                ->references(['company_id', 'id'])->on('attendance_notification_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'recipient_employee_id'], 'att_not_messages_employee_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('attendance_notification_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('case_id');
            $table->string('route_key', 80);
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_not_routes_co_id_uq');
            $table->unique(['company_id', 'case_id', 'route_key'], 'att_not_routes_case_key_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'], 'att_not_routes_case_fk')
                ->references(['company_id', 'id'])->on('attendance_cases')->restrictOnDelete();
        });

        Schema::create('attendance_notification_message_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('message_id');
            $table->foreignId('case_id');
            $table->string('route_key', 80);
            $table->unsignedSmallInteger('cycle_number');
            $table->string('kind', 16);
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_not_links_co_id_uq');
            $table->unique(['company_id', 'case_id', 'route_key', 'cycle_number'], 'att_not_links_reserve_uq');
            $table->index(['company_id', 'message_id'], 'att_not_links_message_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'message_id'], 'att_not_links_message_fk')
                ->references(['company_id', 'id'])->on('attendance_notification_messages')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'], 'att_not_links_case_fk')
                ->references(['company_id', 'id'])->on('attendance_cases')->restrictOnDelete();
        });

        Schema::create('attendance_notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('message_id');
            $table->unsignedTinyInteger('attempt_number');
            $table->string('result', 16);
            $table->string('address');
            $table->string('error_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_not_attempts_co_id_uq');
            $table->unique(['company_id', 'message_id', 'attempt_number', 'result'], 'att_not_attempts_result_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'message_id'], 'att_not_attempts_message_fk')
                ->references(['company_id', 'id'])->on('attendance_notification_messages')->restrictOnDelete();
        });

        foreach (['update', 'delete'] as $operation) {
            DB::unprepared("CREATE TRIGGER `immutable_attendance_notification_attempts_{$operation}` BEFORE ".strtoupper($operation)." ON `attendance_notification_attempts` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_notification_attempts is append-only'; END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_notification_attempts_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_notification_attempts_update`');
        Schema::dropIfExists('attendance_notification_attempts');
        Schema::dropIfExists('attendance_notification_message_cases');
        Schema::dropIfExists('attendance_notification_routes');
        Schema::dropIfExists('attendance_notification_messages');
        Schema::dropIfExists('attendance_notification_batches');
    }
};
