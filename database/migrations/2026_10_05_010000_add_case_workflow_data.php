<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laws', function (Blueprint $table): void {
            $table->string('category')->default('regulation')->index();
        });
        DB::table('laws')->where('kind', 'statute')->where(fn ($query) => $query
            ->where(fn ($law) => $law->where('number', '37')->where('year', 2015))
            ->orWhere(fn ($law) => $law->where('number', '71')->where('year', 1987)))
            ->update(['category' => 'labor']);
        DB::table('laws')->where('kind', 'statute')->where('number', '18')->where('year', 2023)
            ->update(['category' => 'social_security']);

        Schema::table('cases', function (Blueprint $table): void {
            $table->timestamp('legal_review_completed_at')->nullable();
            $table->foreignId('legal_review_completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('legal_review_note')->nullable();
            $table->boolean('session_majority_reached')->default(false);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('leave_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('leave_confirmed_at')->nullable();
        });
        Schema::table('document_approvals', function (Blueprint $table): void {
            $table->foreignId('committee_pool_member_id')->nullable()->after('approver_user_id');
            $table->foreign(['company_id', 'committee_pool_member_id'], 'approval_pool_company_fk')
                ->references(['company_id', 'id'])->on('committee_pool_members')->restrictOnDelete();
        });
        Schema::create('case_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->string('alert_type');
            $table->string('stage');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['case_id', 'alert_type', 'stage']);
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
        });
        Schema::create('case_conflict_clearances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->foreignId('committee_pool_member_id');
            $table->text('reason');
            $table->foreignId('cleared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['case_id', 'committee_pool_member_id']);
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'committee_pool_member_id'], 'clearance_pool_company_fk')
                ->references(['company_id', 'id'])->on('committee_pool_members')->restrictOnDelete();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('notifiable');
            $table->string('type');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('case_conflict_clearances');
        Schema::dropIfExists('case_alerts');
        Schema::table('document_approvals', function (Blueprint $table): void {
            $table->dropForeign('approval_pool_company_fk');
            $table->dropColumn('committee_pool_member_id');
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('leave_confirmed_by');
            $table->dropColumn('leave_confirmed_at');
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legal_review_completed_by');
            $table->dropColumn(['legal_review_completed_at', 'legal_review_note', 'session_majority_reached']);
        });
        Schema::table('laws', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
