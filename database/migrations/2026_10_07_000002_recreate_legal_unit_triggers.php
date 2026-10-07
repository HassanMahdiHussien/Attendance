<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function install(bool $new): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_delete`');
        $statuses = $new ? "'verified_auto', 'verified_human', 'verified_text'" : "'verified_auto', 'verified_human'";
        $columns = ['id', 'created_at', 'law_id', 'company_id', 'version', 'previous_version_id',
            'article_number', 'clause_path', 'official_text', 'source_page', 'extraction_a',
            'extraction_b', 'status', 'verified_by', 'verified_at', 'tags', 'updated_at'];
        if ($new) {
            $columns[] = 'import_run_id';
        }
        $changed = implode(' OR ', array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $columns));
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_update` BEFORE UPDATE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ({$statuses}) AND ({$changed} OR (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) OR (NOT (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND NOT (OLD.`superseded_by_id` IS NULL AND NEW.`superseded_by_id` IS NOT NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_delete` BEFORE DELETE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ({$statuses}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
    }

    public function up(): void
    {
        $this->install(true);
        DB::unprepared("CREATE TRIGGER `immutable_legal_unit_approvals_update` BEFORE UPDATE ON `legal_unit_approvals` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: legal unit approval'; END");
        DB::unprepared("CREATE TRIGGER `immutable_legal_unit_approvals_delete` BEFORE DELETE ON `legal_unit_approvals` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: legal unit approval'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_unit_approvals_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_unit_approvals_update`');
        $this->install(false);
    }
};
