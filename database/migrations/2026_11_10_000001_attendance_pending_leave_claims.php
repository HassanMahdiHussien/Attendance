<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_pending_leave_reconciliations', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->index(['company_id', 'claim_token'], 'attendance_pending_leave_company_claim_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_pending_leave_reconciliations', function (Blueprint $table): void {
            $table->dropIndex('attendance_pending_leave_company_claim_index');
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
