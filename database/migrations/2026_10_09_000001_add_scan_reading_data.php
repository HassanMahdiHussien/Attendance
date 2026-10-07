<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->string('employee_response_origin')->nullable();
            // The scan an AI reading request (response transcription / signature presence) is bound to.
            $table->unsignedBigInteger('ai_request_attachment_id')->nullable();
        });
        Schema::create('response_transcriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('case_id');
            $table->foreignId('attachment_id');
            $table->string('origin');
            $table->longText('text');
            $table->boolean('illegible')->default(false);
            $table->foreignId('ai_generation_log_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'case_id'], 'transcriptions_case_fk')->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'attachment_id'], 'transcriptions_attachment_fk')->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'transcriptions_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('signature_presence_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('case_id');
            $table->foreignId('document_id');
            $table->foreignId('attachment_id');
            $table->json('results');
            $table->foreignId('ai_generation_log_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'id', 'document_id', 'attachment_id'], 'presence_suggestion_binding_unique');
            $table->foreign(['company_id', 'case_id'], 'presence_suggestions_case_fk')->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'], 'presence_suggestions_document_fk')->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign(['company_id', 'attachment_id'], 'presence_suggestions_attachment_fk')->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'], 'presence_suggestions_log_fk')->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('attachment_id')->nullable();
            $table->foreignId('presence_suggestion_id')->nullable();
            $table->string('ai_suggestion')->nullable();
            $table->foreign(['company_id', 'attachment_id'], 'signatures_scan_fk')->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
            $table->foreign(['company_id', 'presence_suggestion_id', 'document_id', 'attachment_id'], 'signatures_suggestion_binding_fk')
                ->references(['company_id', 'id', 'document_id', 'attachment_id'])->on('signature_presence_suggestions')->restrictOnDelete();
        });
        foreach (['response_transcriptions', 'signature_presence_suggestions'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("CREATE TRIGGER `immutable_{$table}_{$operation}` BEFORE ".strtoupper($operation)." ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            }
        }
    }

    public function down(): void
    {
        foreach (['response_transcriptions', 'signature_presence_suggestions'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_{$operation}`");
            }
        }
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropForeign('signatures_suggestion_binding_fk');
            $table->dropForeign('signatures_scan_fk');
            // MySQL keeps the index each foreign key created under the same name; drop it so up() can run again.
            $table->dropIndex('signatures_suggestion_binding_fk');
            $table->dropIndex('signatures_scan_fk');
            $table->dropColumn(['attachment_id', 'presence_suggestion_id', 'ai_suggestion']);
        });
        Schema::dropIfExists('signature_presence_suggestions');
        Schema::dropIfExists('response_transcriptions');
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['employee_response_origin', 'ai_request_attachment_id']));
    }
};
