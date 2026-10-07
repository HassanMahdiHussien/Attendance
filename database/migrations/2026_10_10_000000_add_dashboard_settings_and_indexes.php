<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('hotspot_min_cases')->default(3);
            $table->unsignedSmallInteger('hotspot_window_days')->default(90);
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->index(['company_id', 'status'], 'cases_dashboard_status_idx');
            $table->index(['company_id', 'violation_time'], 'cases_dashboard_incident_idx');
            $table->index(['company_id', 'branch_id', 'violation_time'], 'cases_dashboard_branch_incident_idx');
            $table->index(['company_id', 'defense_letter_issued_at'], 'cases_dashboard_defense_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropIndex('cases_dashboard_status_idx');
            $table->dropIndex('cases_dashboard_incident_idx');
            // The (company_id, branch_id, violation_time) index replaced the composite branch foreign key's own
            // index; restore it before dropping (MySQL 1553).
            $table->index(['company_id', 'branch_id'], 'cases_company_id_branch_id_foreign');
            $table->dropIndex('cases_dashboard_branch_incident_idx');
            $table->dropIndex('cases_dashboard_defense_idx');
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['hotspot_min_cases', 'hotspot_window_days']));
    }
};
