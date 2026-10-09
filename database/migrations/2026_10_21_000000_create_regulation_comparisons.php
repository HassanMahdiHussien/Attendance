<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('case_id');
            $table->string('document_type');
            $table->string('status');
            $table->string('origin');
            $table->text('skip_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('ai_generation_log_id')->nullable();
            $table->uuid('request_token')->nullable();
            $table->unique('request_token');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['case_id', 'document_type', 'status']);
            $table->foreign(['company_id', 'case_id'], 'comparison_company_case_fk')->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'comparison_company_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        Schema::create('regulation_comparison_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('comparison_id');
            $table->foreignId('law_unit_id')->constrained('legal_units')->restrictOnDelete();
            $table->foreignId('regulation_unit_id')->constrained('legal_units')->restrictOnDelete();
            $table->string('ai_verdict')->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('verdict');
            $table->boolean('changed_by_hr')->default(false);
            $table->timestamps();
            $table->unique(['comparison_id', 'law_unit_id', 'regulation_unit_id'], 'comparison_pair_unique');
            $table->foreign(['company_id', 'comparison_id'], 'comparison_pair_parent_fk')->references(['company_id', 'id'])->on('regulation_comparisons')->restrictOnDelete();
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('regulation_comparison_id')->nullable();
            $table->foreignId('supplementary_comparison_id')->nullable();
            $table->foreign(['company_id', 'regulation_comparison_id'], 'document_comparison_company_fk')->references(['company_id', 'id'])->on('regulation_comparisons')->restrictOnDelete();
            $table->foreign(['company_id', 'supplementary_comparison_id'], 'document_supplement_company_fk')->references(['company_id', 'id'])->on('regulation_comparisons')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER comparison_final_update BEFORE UPDATE ON regulation_comparisons FOR EACH ROW BEGIN IF OLD.status IN ('confirmed','skipped') AND (NOT (NEW.id <=> OLD.id) OR NOT (NEW.company_id <=> OLD.company_id) OR NOT (NEW.case_id <=> OLD.case_id) OR NOT (NEW.document_type <=> OLD.document_type) OR NOT (NEW.status <=> OLD.status) OR NOT (NEW.origin <=> OLD.origin) OR NOT (NEW.skip_reason <=> OLD.skip_reason) OR NOT (NEW.confirmed_by <=> OLD.confirmed_by) OR NOT (NEW.confirmed_at <=> OLD.confirmed_at) OR NOT (NEW.ai_generation_log_id <=> OLD.ai_generation_log_id) OR NOT (NEW.request_token <=> OLD.request_token) OR NOT (NEW.created_at <=> OLD.created_at) OR (NOT (NEW.updated_at <=> OLD.updated_at) AND (NEW.consumed_at <=> OLD.consumed_at)) OR (NOT (NEW.consumed_at <=> OLD.consumed_at) AND (OLD.consumed_at IS NOT NULL OR NEW.consumed_at IS NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: final comparison'; END IF; END");
        DB::unprepared("CREATE TRIGGER comparison_final_delete BEFORE DELETE ON regulation_comparisons FOR EACH ROW BEGIN IF OLD.status IN ('confirmed','skipped') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: final comparison'; END IF; END");
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $reference = $event === 'DELETE' ? 'OLD' : 'NEW';
            $oldCheck = $event === 'UPDATE' ? " OR EXISTS (SELECT 1 FROM regulation_comparisons WHERE id = OLD.comparison_id AND status IN ('confirmed','skipped'))" : '';
            DB::unprepared('CREATE TRIGGER comparison_pairs_'.strtolower($event)." BEFORE $event ON regulation_comparison_pairs FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM regulation_comparisons WHERE id = $reference.comparison_id AND status IN ('confirmed','skipped'))$oldCheck THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: final comparison pairs'; END IF; END");
        }
        DB::unprepared("CREATE TRIGGER document_comparison_locked BEFORE UPDATE ON documents FOR EACH ROW BEGIN IF OLD.is_locked = 1 AND (NOT (NEW.regulation_comparison_id <=> OLD.regulation_comparison_id) OR NOT (NEW.supplementary_comparison_id <=> OLD.supplementary_comparison_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: document comparison'; END IF; END");
    }

    public function down(): void
    {
        foreach (['document_comparison_locked', 'comparison_pairs_insert', 'comparison_pairs_update', 'comparison_pairs_delete', 'comparison_final_update', 'comparison_final_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS `$trigger`");
        }
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('document_comparison_company_fk');
            $table->dropForeign('document_supplement_company_fk');
            $table->dropColumn(['regulation_comparison_id', 'supplementary_comparison_id']);
        });
        Schema::dropIfExists('regulation_comparison_pairs');
        Schema::dropIfExists('regulation_comparisons');
    }
};
