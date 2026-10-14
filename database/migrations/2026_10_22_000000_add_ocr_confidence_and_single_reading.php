<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function triggers(string $statuses): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_delete`');
        $columns = ['id', 'created_at', 'law_id', 'company_id', 'version', 'previous_version_id',
            'article_number', 'clause_path', 'official_text', 'source_page', 'extraction_a',
            'extraction_b', 'status', 'verified_by', 'verified_at', 'tags', 'updated_at', 'import_run_id'];
        $changed = implode(' OR ', array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $columns));
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_update` BEFORE UPDATE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ({$statuses}) AND ({$changed} OR (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) OR (NOT (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND NOT (OLD.`superseded_by_id` IS NULL AND NEW.`superseded_by_id` IS NOT NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_delete` BEFORE DELETE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ({$statuses}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
    }

    public function up(): void
    {
        Schema::table('legal_import_pages', function (Blueprint $table): void {
            $table->decimal('ocr_confidence', 5, 2)->nullable();
        });
        $this->triggers("'verified_auto', 'verified_human', 'verified_text', 'verified_single'");
    }

    public function down(): void
    {
        $this->triggers("'verified_auto', 'verified_human', 'verified_text'");
        Schema::table('legal_import_pages', function (Blueprint $table): void {
            $table->dropColumn('ocr_confidence');
        });
    }
};
