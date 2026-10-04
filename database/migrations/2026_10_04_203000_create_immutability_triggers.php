<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER `immutable_case_events_update` BEFORE UPDATE ON `case_events` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: case_events is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_case_events_delete` BEFORE DELETE ON `case_events` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: case_events is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_audit_logs_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_audit_logs_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_ai_generation_logs_update` BEFORE UPDATE ON `ai_generation_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_generation_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_ai_generation_logs_delete` BEFORE DELETE ON `ai_generation_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_generation_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_attachments_update` BEFORE UPDATE ON `attachments` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attachments is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_attachments_delete` BEFORE DELETE ON `attachments` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attachments is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_documents_update` BEFORE UPDATE ON `documents` FOR EACH ROW BEGIN IF OLD.`is_locked` = 1 AND (NOT (NEW.`id` <=> OLD.`id`) OR NOT (NEW.`created_at` <=> OLD.`created_at`) OR NOT (NEW.`company_id` <=> OLD.`company_id`) OR NOT (NEW.`case_id` <=> OLD.`case_id`) OR NOT (NEW.`document_type` <=> OLD.`document_type`) OR NOT (NEW.`version` <=> OLD.`version`) OR NOT (NEW.`previous_version_id` <=> OLD.`previous_version_id`) OR NOT (NEW.`correction_reason` <=> OLD.`correction_reason`) OR NOT (NEW.`is_locked` <=> OLD.`is_locked`) OR NOT (NEW.`reference_number` <=> OLD.`reference_number`) OR NOT (NEW.`reference_year` <=> OLD.`reference_year`) OR NOT (NEW.`reference_is_manual` <=> OLD.`reference_is_manual`) OR NOT (NEW.`issued_at` <=> OLD.`issued_at`) OR NOT (NEW.`issued_by` <=> OLD.`issued_by`) OR NOT (NEW.`second_reviewed_by` <=> OLD.`second_reviewed_by`) OR NOT (NEW.`second_reviewed_at` <=> OLD.`second_reviewed_at`) OR NOT (NEW.`drafted_by` <=> OLD.`drafted_by`) OR NOT (NEW.`penalty_type` <=> OLD.`penalty_type`) OR NOT (NEW.`created_by` <=> OLD.`created_by`) OR (NOT (NEW.`status` <=> OLD.`status`) AND NOT (OLD.`status` = 'issued' AND NEW.`status` = 'superseded')) OR (NOT (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND NOT (OLD.`superseded_by_id` IS NULL AND NEW.`superseded_by_id` IS NOT NULL)) OR (NOT (NEW.`pdf_file_path` <=> OLD.`pdf_file_path`) AND NOT (OLD.`pdf_file_path` IS NULL AND NEW.`pdf_file_path` IS NOT NULL)) OR ((NEW.`status` <=> OLD.`status`) AND (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND (NEW.`pdf_file_path` <=> OLD.`pdf_file_path`)) OR (NOT (NEW.`updated_at` <=> OLD.`updated_at`) AND (NEW.`status` <=> OLD.`status`) AND (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND (NEW.`pdf_file_path` <=> OLD.`pdf_file_path`))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: locked document'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_documents_delete` BEFORE DELETE ON `documents` FOR EACH ROW BEGIN IF OLD.`is_locked` = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: locked document'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_insert` BEFORE INSERT ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_update` BEFORE UPDATE ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_delete` BEFORE DELETE ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_citations_insert` BEFORE INSERT ON `citations` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_citations_update` BEFORE UPDATE ON `citations` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_citations_delete` BEFORE DELETE ON `citations` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_approvals_insert` BEFORE INSERT ON `document_approvals` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_approvals_update` BEFORE UPDATE ON `document_approvals` FOR EACH ROW BEGIN IF NOT (NEW.`id` <=> OLD.`id`) OR NOT (NEW.`created_at` <=> OLD.`created_at`) OR NOT (NEW.`company_id` <=> OLD.`company_id`) OR NOT (NEW.`document_id` <=> OLD.`document_id`) OR NOT (NEW.`approver_user_id` <=> OLD.`approver_user_id`) OR NOT (NEW.`role_category` <=> OLD.`role_category`) OR NOT (NEW.`decision` <=> OLD.`decision`) OR NOT (NEW.`comment` <=> OLD.`comment`) OR NOT (NEW.`languages_present` <=> OLD.`languages_present`) OR NOT (NEW.`decided_at` <=> OLD.`decided_at`) OR NOT (NEW.`updated_at` <=> OLD.`updated_at`) OR NOT (OLD.`invalidated_at` IS NULL AND NEW.`invalidated_at` IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: approval cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_approvals_delete` BEFORE DELETE ON `document_approvals` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: approval cannot be deleted'; END");
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_update` BEFORE UPDATE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ('verified_auto', 'verified_human') AND (NOT (NEW.`id` <=> OLD.`id`) OR NOT (NEW.`created_at` <=> OLD.`created_at`) OR NOT (NEW.`law_id` <=> OLD.`law_id`) OR NOT (NEW.`company_id` <=> OLD.`company_id`) OR NOT (NEW.`version` <=> OLD.`version`) OR NOT (NEW.`previous_version_id` <=> OLD.`previous_version_id`) OR NOT (NEW.`article_number` <=> OLD.`article_number`) OR NOT (NEW.`clause_path` <=> OLD.`clause_path`) OR NOT (NEW.`official_text` <=> OLD.`official_text`) OR NOT (NEW.`source_page` <=> OLD.`source_page`) OR NOT (NEW.`extraction_a` <=> OLD.`extraction_a`) OR NOT (NEW.`extraction_b` <=> OLD.`extraction_b`) OR NOT (NEW.`status` <=> OLD.`status`) OR NOT (NEW.`verified_by` <=> OLD.`verified_by`) OR NOT (NEW.`verified_at` <=> OLD.`verified_at`) OR NOT (NEW.`tags` <=> OLD.`tags`) OR NOT (NEW.`updated_at` <=> OLD.`updated_at`) OR (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) OR (NOT (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND NOT (OLD.`superseded_by_id` IS NULL AND NEW.`superseded_by_id` IS NOT NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_legal_units_delete` BEFORE DELETE ON `legal_units` FOR EACH ROW BEGIN IF OLD.`status` IN ('verified_auto', 'verified_human') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: verified legal unit'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_legal_units_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_approvals_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_approvals_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_approvals_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_citations_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_citations_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_citations_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_contents_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_contents_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_contents_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_documents_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_documents_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attachments_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attachments_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_generation_logs_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_generation_logs_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_audit_logs_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_audit_logs_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_case_events_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_case_events_update`');
    }
};
