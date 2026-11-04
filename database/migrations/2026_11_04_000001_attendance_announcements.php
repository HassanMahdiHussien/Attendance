<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_holiday_versions', function (Blueprint $table): void {
            $table->string('kind')->default('holiday');
        });
        DB::statement('ALTER TABLE `attendance_holiday_versions` ADD COLUMN `departments` JSON NOT NULL DEFAULT (JSON_ARRAY()), ADD COLUMN `employee_ids` JSON NOT NULL DEFAULT (JSON_ARRAY())');
        Schema::table('attendance_holiday_sources', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('attendance_holidays', function (Blueprint $table): void {
            $table->uuid('reprocess_batch_id')->nullable();
        });
        Schema::table('attendance_processing_runs', fn (Blueprint $table) => $table->uuid('reprocess_batch_id')->nullable());
        DB::statement('ALTER TABLE `attendance_days` ADD COLUMN `field_work_holiday_id` BIGINT UNSIGNED NULL, ALGORITHM=INSTANT');
    }

    public function down(): void
    {
        Schema::table('attendance_days', fn (Blueprint $table) => $table->dropColumn('field_work_holiday_id'));
        Schema::table('attendance_processing_runs', fn (Blueprint $table) => $table->dropColumn('reprocess_batch_id'));
        Schema::table('attendance_holidays', fn (Blueprint $table) => $table->dropColumn('reprocess_batch_id'));
        Schema::table('attendance_holiday_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
        Schema::table('attendance_holiday_versions', fn (Blueprint $table) => $table->dropColumn(['kind', 'departments', 'employee_ids']));
    }
};
