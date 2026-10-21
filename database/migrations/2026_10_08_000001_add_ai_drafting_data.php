<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generation_logs', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
    }

    public function down(): void
    {
        Schema::table('ai_generation_logs', function (Blueprint $table): void {
            // The composite unique replaced the company_id foreign key's own index; restore it first.
            $table->index('company_id', 'ai_generation_logs_company_id_foreign');
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
