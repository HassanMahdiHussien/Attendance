<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('branch_id');
            $table->foreignId('created_by_user_id');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'branch_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::table('cases', function (Blueprint $table): void {
            $table->foreignId('incident_id')->nullable();
            $table->text('case_specific_description')->nullable();
            $table->unique(['incident_id', 'employee_id']);
            $table->foreign(['company_id', 'branch_id', 'incident_id'], 'fk_case_incident_branch')
                ->references(['company_id', 'branch_id', 'id'])->on('incidents')->restrictOnDelete();
        });

        Schema::create('violation_report_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('violation_report_id');
            $table->foreignId('employee_id');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['violation_report_id', 'employee_id'], 'report_employees_report_employee_unique');
            $table->foreign(['company_id', 'violation_report_id'], 'report_employees_report_fk')->references(['company_id', 'id'])->on('violation_reports')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('incident_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('incident_id');
            $table->foreignId('recorded_by_user_id');
            $table->text('shared_minutes');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'incident_id'])->references(['company_id', 'id'])->on('incidents')->restrictOnDelete();
            $table->foreign(['company_id', 'recorded_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER immutable_case_incident_update BEFORE UPDATE ON cases FOR EACH ROW BEGIN IF NOT (NEW.incident_id <=> OLD.incident_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: incident membership'; END IF; IF OLD.defense_letter_issued_at IS NOT NULL AND NOT (NEW.case_specific_description <=> OLD.case_specific_description) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: issued case description'; END IF; END");
        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared('CREATE TRIGGER immutable_report_employees_'.strtolower($operation).
                " BEFORE {$operation} ON violation_report_employees FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: report employees are append-only'; END");
            DB::unprepared('CREATE TRIGGER immutable_incident_sessions_'.strtolower($operation).
                " BEFORE {$operation} ON incident_sessions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: incident sessions are append-only'; END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS immutable_case_incident_update');
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS immutable_report_employees_'.$operation);
            DB::unprepared('DROP TRIGGER IF EXISTS immutable_incident_sessions_'.$operation);
        }
        Schema::dropIfExists('incident_sessions');
        Schema::dropIfExists('violation_report_employees');
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropForeign('fk_case_incident_branch');
            $table->dropUnique(['incident_id', 'employee_id']);
            $table->dropColumn('incident_id');
            $table->dropColumn('case_specific_description');
        });
        Schema::dropIfExists('incidents');
    }
};
