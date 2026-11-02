<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_import_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by');
            $table->foreignId('batch_id')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->json('headers');
            $table->unsignedInteger('header_row');
            $table->dateTime('export_at')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('status');
            $table->json('analysis')->nullable();
            $table->unsignedInteger('rows_processed')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'uploaded_by', 'status', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'batch_id'])->references(['company_id', 'id'])->on('attendance_import_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_drafts');
    }
};
