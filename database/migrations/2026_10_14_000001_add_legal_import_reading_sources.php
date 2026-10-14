<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_import_runs', function (Blueprint $table): void {
            $table->foreignId('started_by')->nullable()->change();
            $table->string('reading_mode')->default('now');
            $table->string('reading_model')->nullable();
            $table->string('reading_effort')->nullable();
            $table->string('batch_id')->nullable()->unique();
            $table->string('batch_state')->nullable();
            $table->string('transcription_path')->nullable();
            $table->char('transcription_sha256', 64)->nullable();
            $table->foreignId('transcription_uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('transcription_uploaded_at')->nullable();
            $table->string('transcription_note')->nullable();
            $table->string('secondary_source_path')->nullable();
            $table->char('secondary_source_sha256', 64)->nullable();
            $table->foreignId('secondary_source_uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('secondary_source_uploaded_at')->nullable();
            $table->string('secondary_source_note')->nullable();
        });

        Schema::table('legal_import_pages', function (Blueprint $table): void {
            $table->string('ai_source')->nullable();
        });
        DB::table('legal_import_pages')->where('ai_status', 'done')->whereNotNull('ai_text')
            ->update(['ai_source' => 'api']);
    }

    public function down(): void
    {
        Schema::table('legal_import_pages', fn (Blueprint $table) => $table->dropColumn('ai_source'));
        Schema::table('legal_import_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transcription_uploaded_by');
            $table->dropConstrainedForeignId('secondary_source_uploaded_by');
            $table->dropUnique(['batch_id']);
            $table->dropColumn(['reading_mode', 'reading_model', 'reading_effort', 'batch_id', 'batch_state', 'transcription_path',
                'transcription_sha256', 'transcription_uploaded_at', 'transcription_note',
                'secondary_source_path', 'secondary_source_sha256', 'secondary_source_uploaded_at',
                'secondary_source_note']);
            $table->foreignId('started_by')->nullable(false)->change();
        });
    }
};
