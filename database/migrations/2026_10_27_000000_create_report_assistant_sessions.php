<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->dateTime('occurred_at')->nullable()->change();
            $table->string('occurred_at_text')->nullable();
            $table->boolean('witnesses_none')->default(false);
            $table->boolean('witnesses_unknown')->default(false);
            $table->boolean('employees_unknown')->default(false);
            $table->text('summary')->nullable();
            $table->text('how_started')->nullable();
            $table->text('injuries_damage')->nullable();
        });

        Schema::create('report_assistant_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id');
            $table->foreignId('violation_report_id')->nullable();
            $table->string('status', 20);
            $table->string('language', 2);
            $table->uuid('request_token')->nullable();
            $table->json('transcript');
            $table->json('facts');
            $table->json('confirmed_fields')->nullable();
            $table->string('pending_aspect', 20)->nullable();
            $table->unsignedTinyInteger('question_count')->default(0);
            $table->foreignId('ai_generation_log_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique('violation_report_id');
            $table->foreign(['company_id', 'user_id'], 'report_assistant_user_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'violation_report_id'], 'report_assistant_report_fk')->references(['company_id', 'id'])->on('violation_reports')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'report_assistant_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE report_assistant_sessions ADD CONSTRAINT report_assistant_status_allowed CHECK (status IN ('ready', 'pending', 'manual', 'fallback', 'sent'))");
        DB::unprepared("CREATE TRIGGER report_assistant_sent_immutable BEFORE UPDATE ON report_assistant_sessions FOR EACH ROW BEGIN IF OLD.violation_report_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sent report transcript is immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER report_assistant_sent_no_delete BEFORE DELETE ON report_assistant_sessions FOR EACH ROW BEGIN IF OLD.violation_report_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sent report transcript is immutable'; END IF; END");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS report_assistant_sent_no_delete');
        DB::statement('DROP TRIGGER IF EXISTS report_assistant_sent_immutable');
        Schema::dropIfExists('report_assistant_sessions');
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->dropColumn(['occurred_at_text', 'witnesses_none', 'witnesses_unknown',
                'employees_unknown', 'summary', 'how_started', 'injuries_damage']);
            $table->dateTime('occurred_at')->nullable(false)->change();
        });
    }
};
