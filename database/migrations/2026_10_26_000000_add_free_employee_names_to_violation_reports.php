<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->json('free_employee_names')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->dropColumn('free_employee_names');
        });
    }
};
