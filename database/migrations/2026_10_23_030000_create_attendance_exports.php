<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('requested_by')->nullable();
            $table->string('kind');
            $table->json('filters');
            $table->string('locale', 2);
            $table->string('file_path')->nullable();
            $table->string('file_name');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_exports_company_id_unique');
            $table->index(['company_id', 'created_at'], 'att_exports_company_created_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_export_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('export_id');
            $table->unsignedInteger('sequence');
            $table->string('state');
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_export_events_company_id_unique');
            $table->unique(['company_id', 'export_id', 'sequence'], 'att_export_events_sequence_unique');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'export_id'])->references(['company_id', 'id'])->on('attendance_exports')->restrictOnDelete();
        });

        foreach (['update', 'delete'] as $operation) {
            DB::unprepared("CREATE TRIGGER `immutable_attendance_export_events_{$operation}` BEFORE ".strtoupper($operation)." ON `attendance_export_events` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attendance_export_events is append-only'; END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_export_events_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_export_events_update`');
        Schema::dropIfExists('attendance_export_events');
        Schema::dropIfExists('attendance_exports');
    }
};
