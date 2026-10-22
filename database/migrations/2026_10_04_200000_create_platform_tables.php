<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('enabled_languages')->default(new Expression('(JSON_ARRAY("ar", "en"))'));
            $table->string('subscription_status')->default('trial');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->unique();
            $table->string('primary_role')->default('hr_staff');
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('employee_id');
            $table->dropColumn('primary_role');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('companies');
    }
};
