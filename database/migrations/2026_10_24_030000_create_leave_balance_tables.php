<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->date('balance_date');
            $table->unsignedSmallInteger('balance_year');
            $table->json('mapping');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'file_sha256', 'balance_date'], 'leave_balance_batches_hash_date_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_balance_batch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->unsignedInteger('sequence');
            $table->string('state');
            $table->json('counts')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'sequence'], 'leave_balance_events_sequence_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'], 'leave_balance_events_batch_fk')->references(['company_id', 'id'])->on('leave_balance_batches')->restrictOnDelete();
        });

        Schema::create('leave_balance_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->char('header_signature', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'header_signature'], 'leave_balance_mapping_header_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->foreignId('employee_id');
            $table->date('balance_date');
            $table->unsignedSmallInteger('balance_year');
            $table->decimal('remaining_days', 9, 4);
            $table->decimal('carried_over_days', 9, 4)->nullable();
            $table->decimal('entitlement_days', 9, 4)->nullable();
            $table->decimal('used_days', 9, 4)->nullable();
            $table->json('monthly_used')->nullable();
            $table->unsignedInteger('source_row');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'employee_id'], 'leave_balances_batch_employee_uq');
            $table->index(['company_id', 'employee_id', 'balance_date', 'batch_id'], 'leave_balances_current_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'], 'leave_balances_batch_fk')->references(['company_id', 'id'])->on('leave_balance_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'leave_balances_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('leave_balance_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->foreignId('employee_id')->nullable();
            $table->unsignedInteger('row_number');
            $table->string('employee_code')->nullable();
            $table->string('outcome');
            $table->string('reason_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'row_number'], 'leave_balance_rows_batch_row_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'], 'leave_balance_rows_batch_fk')->references(['company_id', 'id'])->on('leave_balance_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'leave_balance_rows_employee_fk')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        foreach (['leave_balance_batches', 'leave_balance_batch_events', 'leave_balances', 'leave_balance_rows'] as $table) {
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
        }
    }

    public function down(): void
    {
        foreach (['leave_balance_batches', 'leave_balance_batch_events', 'leave_balances', 'leave_balance_rows'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_update`");
        }
        Schema::dropIfExists('leave_balance_rows');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_balance_mappings');
        Schema::dropIfExists('leave_balance_batch_events');
        Schema::dropIfExists('leave_balance_batches');
    }
};
