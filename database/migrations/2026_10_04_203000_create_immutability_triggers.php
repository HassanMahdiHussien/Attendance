<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER `immutable_audit_logs_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_audit_logs_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_ai_generation_logs_update` BEFORE UPDATE ON `ai_generation_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_generation_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER `immutable_ai_generation_logs_delete` BEFORE DELETE ON `ai_generation_logs` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: ai_generation_logs is append-only'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_generation_logs_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_ai_generation_logs_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_audit_logs_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_audit_logs_update`');
    }
};
