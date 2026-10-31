<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('uploaded_by');
            $table->string('kind', 16);
            $table->string('mode', 16);
            $table->date('effective_date');
            $table->json('mapping')->nullable();
            $table->char('token_or_hash', 64);
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->string('status', 16);
            $table->unsignedInteger('row_count');
            $table->foreignId('employee_import_batch_id')->nullable();
            $table->json('result_counts')->nullable();
            $table->string('error', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status', 'id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_import_batch_id'], 'employee_import_runs_batch_tenant_fk')
                ->references(['company_id', 'id'])->on('employee_import_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_runs');
    }
};
