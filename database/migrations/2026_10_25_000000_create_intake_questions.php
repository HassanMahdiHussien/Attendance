<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('status', 20);
            $table->string('language', 10);
            $table->char('input_hash', 64);
            $table->uuid('request_token')->unique();
            $table->json('input');
            $table->json('result')->nullable();
            $table->foreignId('ai_generation_log_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['user_id', 'created_at']);
            $table->foreign(['company_id', 'user_id'], 'intake_check_user_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'intake_check_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE intake_checks ADD CONSTRAINT intake_check_status_allowed CHECK (status IN ('pending', 'ready', 'checklist'))");

        Schema::create('intake_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('violation_report_id')->nullable();
            $table->foreignId('email_draft_id')->nullable();
            $table->foreignId('case_id')->nullable();
            $table->foreignId('intake_check_id')->nullable();
            $table->string('source', 20);
            $table->string('aspect', 20);
            $table->string('question', 500);
            $table->text('answer')->nullable();
            $table->foreignId('answered_by_user_id')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['violation_report_id', 'sort_order']);
            $table->index(['email_draft_id', 'sort_order']);
            $table->index(['case_id', 'sort_order']);
            $table->foreign(['company_id', 'violation_report_id'], 'intake_question_report_fk')->references(['company_id', 'id'])->on('violation_reports')->restrictOnDelete();
            $table->foreign(['company_id', 'email_draft_id'], 'intake_question_email_fk')->references(['company_id', 'id'])->on('email_drafts')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'], 'intake_question_case_fk')->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'intake_check_id'], 'intake_question_check_fk')->references(['company_id', 'id'])->on('intake_checks')->restrictOnDelete();
            $table->foreign(['company_id', 'answered_by_user_id'], 'intake_question_answerer_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE intake_questions ADD CONSTRAINT intake_question_one_parent CHECK ((violation_report_id IS NOT NULL) + (email_draft_id IS NOT NULL) + (case_id IS NOT NULL) = 1)');
        DB::statement("ALTER TABLE intake_questions ADD CONSTRAINT intake_question_source_allowed CHECK (source IN ('ai', 'checklist'))");
        DB::statement("ALTER TABLE intake_questions ADD CONSTRAINT intake_question_aspect_allowed CHECK (aspect IN ('who', 'what', 'when', 'where', 'why', 'how', 'witnesses', 'evidence', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_questions');
        Schema::dropIfExists('intake_checks');
    }
};
