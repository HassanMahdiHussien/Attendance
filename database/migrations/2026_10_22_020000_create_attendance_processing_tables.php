<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_processing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('kind');
            $table->string('trigger_source');
            $table->foreignId('batch_id')->nullable();
            $table->foreignId('device_id')->nullable();
            $table->json('person_codes');
            $table->dateTime('scope_from');
            $table->dateTime('scope_to');
            $table->unsignedInteger('policy_version');
            $table->string('status');
            $table->json('counts')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status', 'created_at'], 'att_runs_company_status_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'device_id'])->references(['company_id', 'id'])->on('attendance_devices')->restrictOnDelete();
        });

        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('transaction_id');
            $table->foreignId('run_id');
            $table->string('person_code')->collation('utf8mb4_bin');
            $table->foreignId('employee_id')->nullable();
            $table->dateTime('punched_at');
            $table->string('role')->nullable();
            $table->string('status');
            $table->string('group_key')->nullable();
            $table->string('reason_code')->nullable();
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'person_code', 'superseded_by_run_id', 'punched_at'], 'att_punches_current_person_idx');
            $table->index(['company_id', 'transaction_id', 'superseded_by_run_id'], 'att_punches_current_tx_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('attendance_transactions')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'att_punches_superseded_fk')->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('attendance_meals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('person_code')->collation('utf8mb4_bin');
            $table->foreignId('employee_id')->nullable();
            $table->string('department_label')->nullable();
            $table->date('meal_date');
            $table->unsignedInteger('punch_count');
            $table->unsignedInteger('repeat_count');
            $table->foreignId('first_transaction_id');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'person_code', 'superseded_by_run_id', 'meal_date'], 'att_meals_current_person_idx');
            $table->index(['company_id', 'meal_date', 'superseded_by_run_id'], 'att_meals_current_day_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'first_transaction_id'], 'att_meals_first_tx_fk')->references(['company_id', 'id'])->on('attendance_transactions')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'att_meals_superseded_fk')->references(['company_id', 'id'])->on('attendance_processing_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_meals');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_processing_runs');
    }
};
