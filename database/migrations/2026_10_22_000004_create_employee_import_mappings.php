<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_mappings');
    }
};
