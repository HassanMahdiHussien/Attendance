<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_pending_leave_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->date('from_date');
            $table->date('to_date');
            // Bumped on every merge so a replay only clears the request it actually covered.
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'employee_id'], 'attendance_pending_leave_company_employee_unique');
            $table->index(['company_id', 'created_at'], 'attendance_pending_leave_company_created_index');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'attendance_pending_leave_employee_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_pending_leave_reconciliations');
    }
};
