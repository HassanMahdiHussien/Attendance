<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_chat_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique('att_chat_settings_company_uq');
            $table->boolean('chat_changes_enabled')->default(false);
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('user_id');
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_chat_conversations_co_id_uq');
            $table->index(['company_id', 'user_id', 'last_message_at'], 'att_chat_conversations_user_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('attendance_chat_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('conversation_id');
            $table->unsignedInteger('sequence');
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->string('model')->nullable();
            $table->string('stop_reason', 40)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'att_chat_turns_co_id_uq');
            $table->unique(['company_id', 'conversation_id', 'sequence'], 'att_chat_turns_sequence_uq');
            $table->index(['company_id', 'conversation_id', 'status', 'sequence'], 'att_chat_turns_queue_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'conversation_id'], 'att_chat_turns_conversation_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_conversations')->restrictOnDelete();
        });

        Schema::create('attendance_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('conversation_id');
            $table->foreignId('turn_id');
            $table->string('role', 16);
            $table->json('content');
            $table->string('status', 24)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_chat_messages_co_id_uq');
            $table->index(['company_id', 'conversation_id', 'id'], 'att_chat_messages_history_idx');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'conversation_id'], 'att_chat_messages_conversation_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_conversations')->restrictOnDelete();
            $table->foreign(['company_id', 'turn_id'], 'att_chat_messages_turn_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_turns')->restrictOnDelete();
        });

        Schema::create('attendance_chat_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('turn_id');
            $table->string('tool_use_id', 120);
            $table->string('name', 80);
            $table->json('input');
            $table->json('result');
            $table->boolean('is_error')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_chat_calls_co_id_uq');
            $table->unique(['company_id', 'turn_id', 'tool_use_id'], 'att_chat_calls_tool_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'turn_id'], 'att_chat_calls_turn_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_turns')->restrictOnDelete();
        });

        Schema::create('attendance_chat_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('conversation_id');
            $table->foreignId('turn_id');
            $table->string('kind', 32)->default('employee_change');
            $table->json('payload');
            $table->string('status', 16)->default('proposed');
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_chat_proposals_co_id_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'conversation_id'], 'att_chat_proposals_conversation_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_conversations')->restrictOnDelete();
            $table->foreign(['company_id', 'turn_id'], 'att_chat_proposals_turn_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_turns')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_chat_proposal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('proposal_id');
            $table->string('event', 24);
            $table->json('details')->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'att_chat_prop_events_co_id_uq');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'proposal_id'], 'att_chat_prop_events_proposal_fk')
                ->references(['company_id', 'id'])->on('attendance_chat_proposals')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        foreach (['attendance_chat_messages', 'attendance_chat_tool_calls', 'attendance_chat_proposal_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("CREATE TRIGGER `immutable_{$table}_{$operation}` BEFORE ".strtoupper($operation)." ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: {$table} is append-only'; END");
            }
        }
        DB::unprepared("CREATE TRIGGER `immutable_attendance_chat_proposals_delete` BEFORE DELETE ON `attendance_chat_proposals` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: proposals cannot be deleted'; END");
        DB::unprepared("CREATE TRIGGER `guard_attendance_chat_proposals_update` BEFORE UPDATE ON `attendance_chat_proposals` FOR EACH ROW BEGIN IF OLD.status <> 'proposed' OR NEW.status NOT IN ('applied', 'cancelled', 'refused') OR NOT (OLD.company_id <=> NEW.company_id AND OLD.conversation_id <=> NEW.conversation_id AND OLD.turn_id <=> NEW.turn_id AND OLD.kind <=> NEW.kind AND OLD.payload <=> NEW.payload AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: proposal content cannot change'; END IF; END");
    }

    public function down(): void
    {
        foreach (['attendance_chat_messages', 'attendance_chat_tool_calls', 'attendance_chat_proposal_events'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_update`");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_attendance_chat_proposals_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `guard_attendance_chat_proposals_update`');
        Schema::dropIfExists('attendance_chat_proposal_events');
        Schema::dropIfExists('attendance_chat_proposals');
        Schema::dropIfExists('attendance_chat_tool_calls');
        Schema::dropIfExists('attendance_chat_messages');
        Schema::dropIfExists('attendance_chat_turns');
        Schema::dropIfExists('attendance_chat_conversations');
        Schema::dropIfExists('attendance_chat_settings');
    }
};
