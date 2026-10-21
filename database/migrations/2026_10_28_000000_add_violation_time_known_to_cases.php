<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->boolean('violation_time_known')->default(true)->after('violation_time');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropColumn('violation_time_known');
        });
    }
};
