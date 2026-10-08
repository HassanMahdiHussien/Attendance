<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('sender_user_id');
            $table->char('message_id_hash', 64)->nullable();
            $table->string('subject')->default('');
            $table->mediumText('body_text');
            $table->boolean('body_truncated')->default(false);
            $table->json('excluded_attachments')->nullable();
            $table->dateTime('received_at');
            $table->string('status')->default('received');
            $table->text('dismiss_reason')->nullable();
            $table->foreignId('case_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'message_id_hash']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign('sender_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
        });

        Schema::create('email_intake_rejections', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('received_at');
            $table->string('sender_domain')->default('');
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('email_intake_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('mailbox');
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->string('outcome');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('email_draft_id')->nullable();
            $table->timestamps();
            $table->unique(['mailbox', 'uidvalidity', 'uid'], 'email_intake_identity');
            $table->foreign('email_draft_id')->references('id')->on('email_drafts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_intake_messages');
        Schema::dropIfExists('email_intake_rejections');
        Schema::dropIfExists('email_drafts');
    }
};
