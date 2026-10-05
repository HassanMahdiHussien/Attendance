<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_documents_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_approvals_update`');
        $this->createDocumentTrigger(true);
        $this->createApprovalTrigger(true);
        DB::unprepared("CREATE TRIGGER `workflow_cases_status_update` BEFORE UPDATE ON `cases` FOR EACH ROW BEGIN IF NOT (NEW.`status` <=> OLD.`status`) AND COALESCE(@displanery_workflow_transition, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: case status changes only through the workflow'; END IF; END");
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $name => $action) {
            DB::unprepared("CREATE TRIGGER `immutable_case_conflict_clearances_{$name}` BEFORE {$action} ON `case_conflict_clearances` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: conflict clearance is append-only'; END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_case_conflict_clearances_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_case_conflict_clearances_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `workflow_cases_status_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_documents_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_document_approvals_update`');
        $this->createDocumentTrigger(false);
        $this->createApprovalTrigger(false);
    }

    private function createDocumentTrigger(bool $withLeave): void
    {
        $protected = ['id', 'created_at', 'company_id', 'case_id', 'document_type', 'version', 'previous_version_id',
            'correction_reason', 'is_locked', 'reference_number', 'reference_year', 'reference_is_manual',
            'issued_at', 'issued_by', 'second_reviewed_by', 'second_reviewed_at', 'drafted_by', 'penalty_type', 'created_by'];
        if ($withLeave) {
            array_push($protected, 'leave_confirmed_by', 'leave_confirmed_at');
        }
        $checks = array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $protected);
        $checks[] = "(NOT (NEW.`status` <=> OLD.`status`) AND NOT (OLD.`status` = 'issued' AND NEW.`status` = 'superseded'))";
        foreach (['superseded_by_id', 'pdf_file_path'] as $column) {
            $checks[] = "(NOT (NEW.`{$column}` <=> OLD.`{$column}`) AND NOT (OLD.`{$column}` IS NULL AND NEW.`{$column}` IS NOT NULL))";
        }
        $checks[] = '((NEW.`status` <=> OLD.`status`) AND (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND (NEW.`pdf_file_path` <=> OLD.`pdf_file_path`))';
        $checks[] = '(NOT (NEW.`updated_at` <=> OLD.`updated_at`) AND (NEW.`status` <=> OLD.`status`) AND (NEW.`superseded_by_id` <=> OLD.`superseded_by_id`) AND (NEW.`pdf_file_path` <=> OLD.`pdf_file_path`))';
        $condition = implode(' OR ', $checks);
        DB::unprepared("CREATE TRIGGER `immutable_documents_update` BEFORE UPDATE ON `documents` FOR EACH ROW BEGIN IF OLD.`is_locked` = 1 AND ({$condition}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: locked document'; END IF; END");
    }

    private function createApprovalTrigger(bool $withPool): void
    {
        $protected = ['id', 'created_at', 'company_id', 'document_id', 'approver_user_id', 'role_category',
            'decision', 'comment', 'languages_present', 'decided_at', 'updated_at'];
        if ($withPool) {
            $protected[] = 'committee_pool_member_id';
        }
        $checks = array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $protected);
        $checks[] = 'NOT (OLD.`invalidated_at` IS NULL AND NEW.`invalidated_at` IS NOT NULL)';
        $condition = implode(' OR ', $checks);
        DB::unprepared("CREATE TRIGGER `immutable_document_approvals_update` BEFORE UPDATE ON `document_approvals` FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: approval cannot be changed'; END IF; END");
    }
};
