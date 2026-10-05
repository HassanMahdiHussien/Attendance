<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('name_en')->nullable();
            $table->string('position_en')->nullable();
            $table->string('department_en')->nullable();
        });
        Schema::table('committee_pool_members', fn (Blueprint $table) => $table->string('person_name_en')->nullable());
        Schema::table('company_letterheads', fn (Blueprint $table) => $table->string('header_mode')->default('logo_text'));
    }

    public function down(): void
    {
        Schema::table('company_letterheads', fn (Blueprint $table) => $table->dropColumn('header_mode'));
        Schema::table('committee_pool_members', fn (Blueprint $table) => $table->dropColumn('person_name_en'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['name_en', 'position_en', 'department_en']));
    }
};
