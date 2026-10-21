<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_file_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('attachment_texts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('attachment_id');
            $table->longText('extracted_text')->nullable();
            $table->string('reading_status', 20);
            $table->foreignId('ai_generation_log_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique('attachment_id');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'attachment_id'])->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
            $table->foreign(['company_id', 'ai_generation_log_id'])->references(['company_id', 'id'])->on('ai_generation_logs')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER attachment_texts_immutable_update BEFORE UPDATE ON attachment_texts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment reading is immutable'; END");
        DB::unprepared("CREATE TRIGGER attachment_texts_immutable_delete BEFORE DELETE ON attachment_texts FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment reading is immutable'; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_texts_immutable_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS attachment_texts_immutable_update');
        Schema::dropIfExists('attachment_texts');
        Schema::dropIfExists('case_file_intakes');
    }
};
