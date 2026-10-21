<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attachments stay append-only, with one narrow exception: files that never became evidence. A reporter's chat
 * draft that was never sent, or an HR upload form whose files were copied onto the case, holds personal data
 * with no owner, so the daily cleanup may remove those files. Report and case evidence can still never be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attachments_delete`');
        DB::unprepared("CREATE TRIGGER `immutable_attachments_delete` BEFORE DELETE ON `attachments` FOR EACH ROW BEGIN
            IF NOT (
                (OLD.attachable_type = 'report_assistant_session' AND EXISTS (
                    SELECT 1 FROM report_assistant_sessions s WHERE s.id = OLD.attachable_id AND s.violation_report_id IS NULL))
                OR OLD.attachable_type = 'case_file_intake'
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attachments is append-only';
            END IF;
        END");
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_texts_immutable_delete');
        DB::unprepared("CREATE TRIGGER attachment_texts_immutable_delete BEFORE DELETE ON attachment_texts FOR EACH ROW BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM attachments a WHERE a.id = OLD.attachment_id AND (
                    (a.attachable_type = 'report_assistant_session' AND EXISTS (
                        SELECT 1 FROM report_assistant_sessions s WHERE s.id = a.attachable_id AND s.violation_report_id IS NULL))
                    OR a.attachable_type = 'case_file_intake')
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment reading is immutable';
            END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attachments_delete`');
        DB::unprepared("CREATE TRIGGER `immutable_attachments_delete` BEFORE DELETE ON `attachments` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: attachments is append-only'; END");
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_texts_immutable_delete');
        DB::unprepared("CREATE TRIGGER attachment_texts_immutable_delete BEFORE DELETE ON attachment_texts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment reading is immutable'; END");
    }
};
