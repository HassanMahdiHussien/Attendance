<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_holiday_sources', fn (Blueprint $table) => $table->json('parts')->nullable());
    }

    public function down(): void
    {
        Schema::table('attendance_holiday_sources', fn (Blueprint $table) => $table->dropColumn('parts'));
    }
};
