<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laws', function (Blueprint $table): void {
            $table->string('import_mode')->default('auto');
            $table->foreignId('supersedes_law_id')->nullable()->constrained('laws')->restrictOnDelete();
            $table->char('source_sha256', 64)->nullable();
        });
        Schema::create('legal_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('law_id')->constrained('laws')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('source_path');
            $table->char('source_sha256', 64);
            $table->unsignedInteger('page_from')->nullable();
            $table->unsignedInteger('page_to')->nullable();
            $table->string('mode');
            $table->string('state')->default('queued');
            $table->json('counts')->nullable();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['law_id', 'state']);
        });
        Schema::create('legal_import_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('legal_import_runs')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('image_path');
            $table->char('image_sha256', 64);
            $table->string('text_status')->default('pending');
            $table->string('text_method')->nullable();
            $table->longText('text')->nullable();
            $table->string('ai_status')->default('pending');
            $table->longText('ai_text')->nullable();
            $table->boolean('ai_illegible')->default(false);
            $table->unsignedSmallInteger('attempts_text')->default(0);
            $table->unsignedSmallInteger('attempts_ai')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'page_number']);
            $table->foreign(['company_id', 'run_id'], 'import_page_company_run_fk')->references(['company_id', 'id'])->on('legal_import_runs')->restrictOnDelete();
        });
        Schema::table('legal_units', function (Blueprint $table): void {
            $table->foreignId('import_run_id')->nullable()->constrained('legal_import_runs')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
        });
        Schema::create('legal_unit_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('legal_unit_id');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique('legal_unit_id');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'legal_unit_id'], 'approval_company_unit_fk')->references(['company_id', 'id'])->on('legal_units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_unit_approvals');
        Schema::table('legal_units', function (Blueprint $table): void {
            // The composite unique replaced the company_id foreign key's own index; restore it first.
            $table->index('company_id', 'legal_units_company_id_foreign');
            $table->dropUnique(['company_id', 'id']);
            $table->dropConstrainedForeignId('import_run_id');
        });
        Schema::dropIfExists('legal_import_pages');
        Schema::dropIfExists('legal_import_runs');
        Schema::table('laws', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_law_id');
            $table->dropColumn(['import_mode', 'source_sha256']);
        });
    }
};
