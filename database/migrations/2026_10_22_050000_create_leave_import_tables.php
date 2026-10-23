<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->dateTime('snapshot_at');
            $table->string('scope');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('mapping');
            $table->json('summary');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'file_sha256'], 'leave_batches_hash_uq');
            $table->index(['company_id', 'snapshot_at'], 'leave_batches_snapshot_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_import_batch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->unsignedInteger('sequence');
            $table->string('state');
            $table->json('counts')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'sequence'], 'leave_batch_events_sequence_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('leave_import_batches')->restrictOnDelete();
        });

        Schema::create('leave_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->char('header_signature', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'header_signature'], 'leave_mappings_signature_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('other_name')->nullable();
            $table->string('normalized_key', 512);
            $table->boolean('covers');
            $table->string('consumes');
            $table->boolean('is_mapped');
            $table->foreignId('mapped_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'normalized_key'], 'leave_types_key_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('mapped_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->unsignedSmallInteger('credit_expiry_days')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('source');
            $table->string('external_id')->collation('utf8mb4_bin');
            $table->foreignId('employee_id')->nullable();
            $table->string('owner_code')->collation('utf8mb4_bin');
            $table->foreignId('leave_type_id')->nullable();
            $table->string('status');
            $table->string('raw_status')->nullable();
            $table->date('rejected_on')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('duration_days', 6, 1)->nullable();
            $table->string('approver_name')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->foreignId('current_version_id')->nullable();
            $table->dateTime('latest_snapshot_at')->nullable();
            $table->foreignId('latest_batch_id')->nullable();
            $table->json('flags');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source', 'external_id'], 'leave_requests_identity_uq');
            $table->index(['company_id', 'employee_id', 'start_date'], 'leave_requests_employee_date_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'])->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'latest_batch_id'])->references(['company_id', 'id'])->on('leave_import_batches')->restrictOnDelete();
        });

        Schema::create('leave_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->foreignId('request_id')->nullable();
            $table->unsignedInteger('row_number');
            $table->json('raw_values');
            $table->json('errors');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'row_number'], 'leave_rows_batch_number_uq');
            $table->index(['company_id', 'request_id'], 'leave_rows_request_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('leave_import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
        });

        Schema::create('leave_request_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('request_id');
            $table->foreignId('batch_id');
            $table->unsignedInteger('row_number');
            $table->dateTime('snapshot_at');
            $table->json('values');
            $table->json('raw_values');
            $table->timestamp('seen_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'row_number'], 'leave_versions_batch_row_uq');
            $table->index(['company_id', 'request_id', 'snapshot_at', 'batch_id'], 'leave_versions_order_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('leave_import_batches')->restrictOnDelete();
        });

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreign(['company_id', 'current_version_id'], 'leave_requests_current_version_fk')->references(['company_id', 'id'])->on('leave_request_versions')->restrictOnDelete();
        });

        Schema::create('leave_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('request_id');
            $table->foreignId('employee_id')->nullable();
            $table->date('work_date')->nullable();
            $table->string('target_kind')->nullable();
            $table->string('decision');
            $table->json('values')->nullable();
            $table->text('reason');
            $table->foreignId('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'request_id', 'id'], 'leave_decisions_request_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('leave_processing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('trigger_source');
            $table->json('scope');
            $table->string('status');
            $table->json('counts')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('leave_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('request_id');
            $table->foreignId('employee_id');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'work_date', 'superseded_by_run_id'], 'leave_days_current_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'leave_days_superseded_fk')->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
        });

        Schema::create('leave_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->foreignId('source_day_id');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->unsignedSmallInteger('minutes');
            $table->date('expires_on')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'superseded_by_run_id'], 'leave_credits_current_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'source_day_id'])->references(['company_id', 'id'])->on('leave_request_days')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'leave_credits_superseded_fk')->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
        });

        Schema::create('leave_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('employee_id');
            $table->foreignId('source_request_day_id')->nullable();
            $table->foreignId('source_credit_id')->nullable();
            $table->foreignId('target_day_id');
            $table->string('target_kind');
            $table->unsignedSmallInteger('minutes');
            $table->json('explanation');
            $table->foreignId('run_id');
            $table->foreignId('superseded_by_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_id', 'superseded_by_run_id'], 'leave_allocations_current_idx');
            $table->index(['company_id', 'target_day_id'], 'leave_allocations_target_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'source_request_day_id'], 'leave_allocations_source_day_fk')->references(['company_id', 'id'])->on('leave_request_days')->restrictOnDelete();
            $table->foreign(['company_id', 'source_credit_id'], 'leave_allocations_credit_fk')->references(['company_id', 'id'])->on('leave_credits')->restrictOnDelete();
            $table->foreign(['company_id', 'target_day_id'])->references(['company_id', 'id'])->on('attendance_days')->restrictOnDelete();
            $table->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_run_id'], 'leave_allocations_superseded_fk')->references(['company_id', 'id'])->on('leave_processing_runs')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE `leave_allocations` ADD CONSTRAINT `leave_allocations_one_source_chk` CHECK ((`source_request_day_id` IS NULL) <> (`source_credit_id` IS NULL))');

        foreach (['leave_import_batches', 'leave_import_batch_events', 'leave_import_rows', 'leave_request_versions', 'leave_request_decisions'] as $table) {
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
        }
    }

    public function down(): void
    {
        foreach (['leave_import_batches', 'leave_import_batch_events', 'leave_import_rows', 'leave_request_versions', 'leave_request_decisions'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_update`");
        }
        Schema::dropIfExists('leave_allocations');
        Schema::dropIfExists('leave_credits');
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_processing_runs');
        Schema::dropIfExists('leave_request_decisions');
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign('leave_requests_current_version_fk');
        });
        Schema::dropIfExists('leave_request_versions');
        Schema::dropIfExists('leave_import_rows');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_settings');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('leave_import_mappings');
        Schema::dropIfExists('leave_import_batch_events');
        Schema::dropIfExists('leave_import_batches');
    }
};
