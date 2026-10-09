<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->json('names');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        foreach (['cases', 'violation_reports'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('violation_type_id')->nullable()->after('violation_type');
                $table->foreign(['company_id', 'violation_type_id'])->references(['company_id', 'id'])
                    ->on('violation_types')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['cases', 'violation_reports'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id', 'violation_type_id']);
                $table->dropColumn('violation_type_id');
            });
        }
        Schema::dropIfExists('violation_types');
    }
};
