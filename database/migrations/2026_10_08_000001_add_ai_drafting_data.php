<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laws', fn (Blueprint $table) => $table->string('name_en')->nullable());
        Schema::table('citations', function (Blueprint $table): void {
            $table->text('override_reason')->nullable();
            $table->char('claim_hash', 64)->nullable();
            $table->unique(['document_id', 'legal_unit_id', 'claim_hash'], 'citations_document_unit_claim_unique');
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->uuid('ai_request_id')->nullable();
            $table->string('ai_request_document_type')->nullable();
            $table->string('ai_request_from_status')->nullable();
            $table->timestamp('ai_request_started_at')->nullable();
        });
        Schema::table('ai_generation_logs', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::create('ai_draft_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('case_id');
            $table->foreignId('document_id');
            $table->foreignId('ai_generation_log_id');
            $table->json('notes');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'case_id'], 'ai_notes_company_case_fk')->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'], 'ai_notes_company_document_fk')->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'ai_notes_company_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        Schema::create('legal_unit_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_unit_id')->constrained('legal_units')->restrictOnDelete();
            $table->string('topic');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['legal_unit_id', 'topic']);
            $table->index('topic');
        });
        DB::unprepared("CREATE TRIGGER `immutable_ai_draft_notes_update` BEFORE UPDATE ON `ai_draft_notes` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_draft_notes is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_ai_draft_notes_delete` BEFORE DELETE ON `ai_draft_notes` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_draft_notes is append-only'; END");
        DB::unprepared("CREATE TRIGGER `legal_unit_topics_statute_insert` BEFORE INSERT ON `legal_unit_topics` FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM `legal_units` u JOIN `laws` l ON l.`id` = u.`law_id` WHERE u.`id` = NEW.`legal_unit_id` AND u.`company_id` IS NULL AND l.`kind` = 'statute') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TENANT: topics require a platform statute'; END IF; END");
        DB::unprepared("CREATE TRIGGER `legal_unit_topics_statute_update` BEFORE UPDATE ON `legal_unit_topics` FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM `legal_units` u JOIN `laws` l ON l.`id` = u.`law_id` WHERE u.`id` = NEW.`legal_unit_id` AND u.`company_id` IS NULL AND l.`kind` = 'statute') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TENANT: topics require a platform statute'; END IF; END");
        $this->recreateCitationUpdateTrigger();
    }

    public function down(): void
    {
        $this->recreateCitationUpdateTrigger();
        DB::unprepared('DROP TRIGGER IF EXISTS `legal_unit_topics_statute_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `legal_unit_topics_statute_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_draft_notes_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_draft_notes_update`');
        Schema::dropIfExists('legal_unit_topics');
        Schema::dropIfExists('ai_draft_notes');
        Schema::table('ai_generation_logs', function (Blueprint $table): void {
            // The composite unique replaced the company_id foreign key's own index; restore it first.
            $table->index('company_id', 'ai_generation_logs_company_id_foreign');
            $table->dropUnique(['company_id', 'id']);
        });
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['ai_request_id', 'ai_request_document_type', 'ai_request_from_status', 'ai_request_started_at']));
        Schema::table('citations', function (Blueprint $table): void {
            $table->dropUnique('citations_document_unit_claim_unique');
            $table->dropColumn(['override_reason', 'claim_hash']);
        });
        Schema::table('laws', fn (Blueprint $table) => $table->dropColumn('name_en'));
    }

    private function recreateCitationUpdateTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_citations_update`');
        DB::unprepared("CREATE TRIGGER `immutable_citations_update` BEFORE UPDATE ON `citations` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
    }
};
