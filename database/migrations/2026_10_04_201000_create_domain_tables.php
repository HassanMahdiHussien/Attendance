<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('jurisdiction');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->string('employee_code');
            $table->string('name');
            $table->string('department');
            $table->string('position');
            $table->date('hire_date');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_code']);
        });

        Schema::create('company_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('sequence_key');
            $table->smallInteger('year')->default(0);
            $table->unsignedInteger('next_value');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'sequence_key', 'year']);
        });

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id');
            $table->foreignId('branch_id');
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'user_id', 'branch_id']);
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::table('company_sequences', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::table('branch_user', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['employee_id']));
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('company_sequences');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('branches');
        Schema::enableForeignKeyConstraints();
    }
};
