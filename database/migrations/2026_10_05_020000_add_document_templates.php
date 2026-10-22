<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->string('gender')->default('unspecified'));
        DB::statement("ALTER TABLE `employees` ADD CONSTRAINT `employees_gender_check` CHECK (`gender` IN ('male', 'female', 'unspecified'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `employees` DROP CHECK `employees_gender_check`');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('gender'));
    }
};
