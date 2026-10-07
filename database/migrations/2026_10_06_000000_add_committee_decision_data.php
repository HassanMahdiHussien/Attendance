<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'users_company_id_unique');
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->foreignId('minutes_typed_by_pool_member_id')->nullable();
            $table->timestamp('legal_review_cancelled_at')->nullable();
            $table->foreignId('legal_review_cancelled_by')->nullable();
            $table->text('legal_review_cancel_reason')->nullable();
            $table->foreign(['company_id', 'minutes_typed_by_pool_member_id'], 'case_minutes_member_company_fk')
                ->references(['company_id', 'id'])->on('committee_pool_members')->restrictOnDelete();
            $table->foreign(['company_id', 'legal_review_cancelled_by'], 'case_review_cancel_user_company_fk')
                ->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('checked_by')->nullable();
            $table->foreign(['company_id', 'checked_by'], 'signature_checker_company_fk')
                ->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropForeign('signature_checker_company_fk');
            $table->dropColumn('checked_by');
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropForeign('case_minutes_member_company_fk');
            $table->dropForeign('case_review_cancel_user_company_fk');
            $table->dropColumn(['minutes_typed_by_pool_member_id', 'legal_review_cancelled_at', 'legal_review_cancelled_by', 'legal_review_cancel_reason']);
        });
        Schema::table('users', function (Blueprint $table): void {
            // MySQL dropped the implicit index of users.company_id's foreign key when the composite
            // unique index could serve it; restore a plain index before removing the composite one.
            $table->index('company_id', 'users_company_id_foreign');
            $table->dropUnique('users_company_id_unique');
        });
    }
};
