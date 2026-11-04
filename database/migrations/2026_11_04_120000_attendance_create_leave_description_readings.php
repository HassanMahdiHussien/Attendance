<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_description_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('request_id');
            $table->string('text_hash', 64);
            $table->string('category')->nullable();
            $table->json('worked_dates')->nullable();
            $table->date('settled_from')->nullable();
            $table->date('settled_to')->nullable();
            $table->decimal('hours', 7, 2)->nullable();
            $table->string('confidence')->nullable();
            $table->string('status');
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('ai_log_id')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'request_id']);
            $table->index(['company_id', 'status', 'category']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_log_id'])->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_description_readings');
    }
};
