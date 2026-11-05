<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_month_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->date('month');
            $table->json('attendance')->nullable();
            $table->json('overtime')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_dirty')->default(true);
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_id', 'month'], 'att_month_employee_month_uq');
            $table->index(['company_id', 'month', 'is_dirty'], 'att_month_dirty_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'att_month_employee_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_month_summaries');
    }
};
