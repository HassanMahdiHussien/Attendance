<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_transactions', function (Blueprint $table): void {
            $table->index(['company_id', 'device_id', 'punched_at'], 'att_tx_device_time_idx');
        });
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->index(['company_id', 'type', 'subject_key'], 'att_issues_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->dropIndex('att_issues_subject_idx');
        });
        Schema::table('attendance_transactions', function (Blueprint $table): void {
            $table->dropIndex('att_tx_device_time_idx');
        });
    }
};
