<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('serial_number', 128)->collation('utf8mb4_bin');
            $table->string('name');
            $table->string('status')->default('pending');
            $table->string('suggested_role')->nullable();
            $table->foreignId('suggested_branch_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'serial_number']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'suggested_branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('attendance_device_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('device_id');
            $table->date('effective_from');
            $table->string('role');
            $table->foreignId('branch_id');
            $table->foreignId('approved_by')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'device_id', 'effective_from', 'id'], 'attendance_device_settings_as_of');
            $table->index(['company_id', 'branch_id', 'effective_from'], 'attendance_device_settings_branch');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'device_id'])->references(['company_id', 'id'])->on('attendance_devices')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->dateTime('export_at')->nullable();
            $table->dateTime('coverage_from');
            $table->dateTime('coverage_to');
            $table->json('coverage_branch_ids');
            $table->json('mapping');
            $table->json('summary');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'file_sha256']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_import_batch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->unsignedInteger('sequence');
            $table->string('state');
            $table->json('counts')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'sequence'], 'attendance_batch_event_sequence');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
        });

        Schema::create('attendance_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->char('header_signature', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'header_signature']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('person_code')->collation('utf8mb4_bin');
            $table->string('person_name')->nullable();
            $table->string('department_label')->nullable();
            $table->date('punch_date');
            $table->string('punch_time');
            $table->dateTime('punched_at');
            $table->string('punch_state', 16)->collation('utf8mb4_bin');
            $table->string('serial_number', 128)->collation('utf8mb4_bin');
            $table->foreignId('device_id');
            $table->string('device_name')->nullable();
            $table->string('data_source')->nullable();
            $table->foreignId('first_batch_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'person_code', 'punched_at', 'punch_state', 'serial_number'], 'attendance_transactions_natural_key');
            $table->index(['company_id', 'person_code', 'punched_at']);
            $table->index(['company_id', 'serial_number', 'punched_at'], 'att_transactions_co_serial_at_idx');
            $table->index(['company_id', 'device_id']);
            $table->index(['company_id', 'first_batch_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'first_batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'device_id'])->references(['company_id', 'id'])->on('attendance_devices')->restrictOnDelete();
        });

        Schema::create('attendance_transaction_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('transaction_id')->nullable();
            $table->foreignId('batch_id');
            $table->unsignedInteger('row_number');
            $table->boolean('duplicate_in_file');
            $table->longText('person_code');
            $table->longText('person_name')->nullable();
            $table->longText('department_label')->nullable();
            $table->longText('punch_date');
            $table->longText('punch_time');
            $table->longText('punch_state');
            $table->longText('serial_number');
            $table->longText('device_name')->nullable();
            $table->longText('data_source')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'batch_id', 'row_number'], 'attendance_sources_batch_row');
            $table->index(['company_id', 'transaction_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'transaction_id'])->references(['company_id', 'id'])->on('attendance_transactions')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
        });

        Schema::create('attendance_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('person_code')->collation('utf8mb4_bin');
            $table->string('kind');
            $table->foreignId('employee_id')->nullable();
            $table->string('department_label')->nullable();
            $table->string('classification_reason');
            $table->timestamp('classified_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'person_code']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });

        Schema::create('attendance_coverage_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('batch_id');
            $table->foreignId('branch_id');
            $table->dateTime('coverage_from');
            $table->dateTime('coverage_to');
            $table->string('kind');
            $table->boolean('is_confirmed')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'branch_id', 'kind', 'is_confirmed', 'coverage_from', 'coverage_to'], 'attendance_coverage_lookup');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('data_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('type');
            $table->string('subject_type');
            $table->string('subject_key');
            $table->string('open_key', 512)->nullable();
            $table->foreignId('batch_id')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status')->default('open');
            $table->string('note')->nullable();
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'open_key']);
            $table->index(['company_id', 'status', 'type']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->restrictOnDelete();
        });

        foreach (['attendance_device_settings', 'attendance_import_batches', 'attendance_import_batch_events', 'attendance_transactions', 'attendance_transaction_sources'] as $table) {
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            DB::unprepared("CREATE TRIGGER `immutable_{$table}_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
        }
    }

    public function down(): void
    {
        foreach (['attendance_device_settings', 'attendance_import_batches', 'attendance_import_batch_events', 'attendance_transactions', 'attendance_transaction_sources'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_update`");
        }
        foreach (['data_issues', 'attendance_coverage_intervals', 'attendance_people', 'attendance_transaction_sources', 'attendance_transactions', 'attendance_import_mappings', 'attendance_import_batch_events', 'attendance_import_batches', 'attendance_device_settings', 'attendance_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
