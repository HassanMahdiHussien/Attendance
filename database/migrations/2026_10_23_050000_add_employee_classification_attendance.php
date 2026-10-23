<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('staff_category', 1)->nullable();
            $table->string('legal_entity')->nullable();
        });

        DB::statement("ALTER TABLE `employees` ADD CONSTRAINT `employees_staff_category_check` CHECK (`staff_category` IS NULL OR `staff_category` IN ('B', 'W'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `employees` DROP CHECK `employees_staff_category_check`');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['staff_category', 'legal_entity']);
        });
    }
};
