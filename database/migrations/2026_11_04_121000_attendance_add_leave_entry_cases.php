<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_cases', function (Blueprint $table): void {
            $table->string('case_kind', 32)->default('day');
            $table->foreignId('leave_request_id')->nullable();
            $table->string('case_key')->storedAs("CASE WHEN `case_kind` = 'leave_entry' THEN CONCAT('leave:', IFNULL(`leave_request_id`, 0)) ELSE CONCAT('day:', `employee_id`, ':', `work_date`) END");
            $table->foreign(['company_id', 'leave_request_id'], 'att_cases_leave_request_fk')
                ->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
        });
        Schema::table('attendance_cases', function (Blueprint $table): void {
            // The employee foreign key needs an index on these columns before the unique one can go.
            $table->index(['company_id', 'employee_id', 'work_date'], 'att_cases_employee_date_idx');
            $table->dropUnique('att_cases_employee_date_uq');
            $table->unique(['company_id', 'case_key'], 'att_cases_company_key_uq');
            $table->index(['company_id', 'case_kind', 'state'], 'att_cases_kind_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_cases', function (Blueprint $table): void {
            $table->dropUnique('att_cases_company_key_uq');
            $table->dropIndex('att_cases_kind_state_idx');
            $table->dropForeign('att_cases_leave_request_fk');
            $table->dropColumn(['case_kind', 'leave_request_id', 'case_key']);
            $table->unique(['company_id', 'employee_id', 'work_date'], 'att_cases_employee_date_uq');
            $table->dropIndex('att_cases_employee_date_idx');
        });
    }
};
