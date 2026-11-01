<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->index(['company_id', 'reviewed', 'status', 'superseded_by_run_id', 'work_date'],
                'att_days_review_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropIndex('att_days_review_queue_idx');
        });
    }
};
