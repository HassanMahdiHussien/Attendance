<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_holiday_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime');
            $table->char('sha256', 64);
            $table->string('ai_status')->default('pending');
            $table->foreignId('ai_log_id')->nullable();
            $table->json('proposal')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_holiday_sources_co_id_uq');
            $table->unique(['company_id', 'sha256'], 'att_holiday_sources_co_sha_uq');
            $table->index(['company_id', 'ai_log_id'], 'att_holiday_sources_log_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_log_id'], 'att_holiday_sources_log_fk')
                ->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });

        Schema::create('attendance_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('name');
            $table->foreignId('current_version_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_holidays_co_id_uq');
            $table->index(['company_id', 'status'], 'att_holidays_co_status_idx');
            $table->index(['company_id', 'current_version_id'], 'att_holidays_version_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('attendance_holiday_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('holiday_id');
            $table->unsignedInteger('version');
            $table->foreignId('source_id')->nullable();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->json('ranges');
            $table->json('legal_entities');
            $table->json('branch_ids');
            $table->json('work_patterns');
            $table->text('exceptions_note')->nullable();
            $table->string('status');
            $table->foreignId('decided_by');
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_holiday_versions_co_id_uq');
            $table->unique(['company_id', 'holiday_id', 'version'], 'att_holiday_versions_number_uq');
            $table->index(['company_id', 'source_id'], 'att_holiday_versions_source_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'holiday_id'], 'att_holiday_versions_holiday_fk')
                ->references(['company_id', 'id'])->on('attendance_holidays')->restrictOnDelete();
            $table->foreign(['company_id', 'source_id'], 'att_holiday_versions_source_fk')
                ->references(['company_id', 'id'])->on('attendance_holiday_sources')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('attendance_holidays', function (Blueprint $table): void {
            $table->foreign(['company_id', 'current_version_id'], 'att_holidays_current_version_fk')
                ->references(['company_id', 'id'])->on('attendance_holiday_versions')->restrictOnDelete();
        });

        Schema::table('attendance_days', fn (Blueprint $table) => $table->json('holiday_ids')->nullable());

        foreach (['attendance_holiday_versions', 'attendance_holiday_sources'] as $table) {
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} cannot be deleted'; END");
        }
        DB::unprepared("CREATE TRIGGER `immutable_attendance_holiday_versions_update` BEFORE UPDATE ON `attendance_holiday_versions` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_holiday_versions is append-only'; END");
        DB::unprepared("CREATE TRIGGER `holiday_source_file_immutable` BEFORE UPDATE ON `attendance_holiday_sources` FOR EACH ROW BEGIN IF NOT (OLD.company_id <=> NEW.company_id) OR NOT (OLD.uploaded_by <=> NEW.uploaded_by) OR NOT (OLD.file_path <=> NEW.file_path) OR NOT (OLD.file_name <=> NEW.file_name) OR NOT (OLD.mime <=> NEW.mime) OR NOT (OLD.sha256 <=> NEW.sha256) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: holiday source file fields'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `holiday_source_file_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_holiday_versions_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_holiday_versions_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_holiday_sources_delete`');
        Schema::table('attendance_days', fn (Blueprint $table) => $table->dropColumn('holiday_ids'));
        Schema::table('attendance_holidays', function (Blueprint $table): void {
            $table->dropForeign('att_holidays_current_version_fk');
        });
        Schema::dropIfExists('attendance_holiday_versions');
        Schema::dropIfExists('attendance_holidays');
        Schema::dropIfExists('attendance_holiday_sources');
    }
};
