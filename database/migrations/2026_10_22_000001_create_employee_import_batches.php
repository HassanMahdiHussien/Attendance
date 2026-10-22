<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('uploaded_by')->nullable();
            $table->string('mode');
            $table->date('effective_date');
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->json('mapping');
            $table->json('counts');
            $table->json('summary');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'file_sha256']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER `immutable_employee_import_batches_update` BEFORE UPDATE ON `employee_import_batches` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: employee_import_batches is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_employee_import_batches_delete` BEFORE DELETE ON `employee_import_batches` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: employee_import_batches is append-only'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_employee_import_batches_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_employee_import_batches_update`');
        Schema::dropIfExists('employee_import_batches');
    }
};
