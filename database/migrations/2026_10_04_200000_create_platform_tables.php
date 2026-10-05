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
            $table->string('letterhead_logo_path')->nullable();
            $table->string('letterhead_address')->nullable();
            $table->string('letterhead_phone')->nullable();
            $table->string('letterhead_email')->nullable();
            $table->json('enabled_languages')->default(new Expression('(JSON_ARRAY("ar", "en"))'));
            $table->boolean('has_internal_regulation')->default(false);
            $table->string('internal_regulation_file_path')->nullable();
            $table->boolean('requires_second_review')->default(false);
            $table->string('subscription_status')->default('trial');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (['hr_partners', 'law_firm_partners'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('contact_email');
                $table->timestamps();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->unique();
            $table->string('primary_role')->default('company_admin');
            $table->foreignId('law_firm_partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('hr_partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('company_hr_partner', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hr_partner_id')->constrained()->restrictOnDelete();
            $table->boolean('full_case_access')->default(false);
            $table->primary(['company_id', 'hr_partner_id']);
        });

        Schema::create('company_law_firm_partner', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('law_firm_partner_id')->constrained()->restrictOnDelete();
            $table->primary(['company_id', 'law_firm_partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_law_firm_partner');
        Schema::dropIfExists('company_hr_partner');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('employee_id');
            $table->dropColumn('primary_role');
            $table->dropConstrainedForeignId('law_firm_partner_id');
            $table->dropConstrainedForeignId('hr_partner_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('law_firm_partners');
        Schema::dropIfExists('hr_partners');
        Schema::dropIfExists('companies');
    }
};
