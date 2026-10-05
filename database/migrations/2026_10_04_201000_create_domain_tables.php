<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('jurisdiction');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->string('employee_code');
            $table->string('name');
            $table->string('department');
            $table->string('position');
            $table->date('hire_date');
            $table->string('email')->nullable();
            $table->boolean('is_general_manager')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'employee_code']);
        });

        Schema::create('committee_mandates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('order_number');
            $table->date('order_date');
            $table->date('effective_from');
            $table->timestamp('superseded_at')->nullable();
            $table->string('source_file_path')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('committee_pool_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('mandate_id');
            $table->foreignId('branch_id')->nullable();
            $table->string('role_type');
            $table->string('person_name');
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('violation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->foreignId('reporter_user_id')->nullable();
            $table->string('source');
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('location_id')->nullable();
            $table->string('place_freetext')->nullable();
            $table->dateTime('occurred_at');
            $table->string('violation_type');
            $table->foreignId('alleged_responsible_employee_id')->nullable();
            $table->string('alleged_responsible_person_freetext')->nullable();
            $table->text('description');
            $table->string('status')->default('received');
            $table->foreignId('case_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('report_witnesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('violation_report_id');
            $table->string('name');
            $table->foreignId('employee_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('branch_id');
            $table->foreignId('employee_id');
            $table->unsignedInteger('case_number');
            $table->foreignId('violation_report_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('jurisdiction_at_opening');
            $table->foreignId('law_version_id')->nullable();
            $table->foreignId('opened_by_user_id');
            $table->foreignId('assigned_hr_user_id')->nullable();
            $table->foreignId('location_id')->nullable();
            $table->string('violation_place_freetext')->nullable();
            $table->dateTime('violation_time');
            $table->string('violation_type');
            $table->text('description')->nullable();
            $table->foreignId('alleged_responsible_employee_id')->nullable();
            $table->string('alleged_responsible_person_freetext')->nullable();
            $table->boolean('include_prior_record')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->foreignId('legal_review_requested_by')->nullable();
            $table->timestamp('legal_review_requested_at')->nullable();
            $table->timestamp('defense_letter_issued_at')->nullable();
            $table->foreignId('committee_mandate_id')->nullable();
            $table->text('employee_response_text')->nullable();
            $table->foreignId('employee_response_checked_by')->nullable();
            $table->timestamp('employee_response_checked_at')->nullable();
            $table->text('committee_session_notes')->nullable();
            $table->timestamp('committee_session_held_at')->nullable();
            $table->string('outcome')->nullable();
            $table->string('defense_acceptance')->nullable();
            $table->timestamp('decision_issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'case_number']);
        });

        Schema::create('case_witnesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->string('name');
            $table->foreignId('employee_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('event_type');
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('case_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->foreignId('linked_case_id');
            $table->string('link_type');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['case_id', 'linked_case_id', 'link_type']);
        });

        Schema::create('committee_session_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->foreignId('committee_pool_member_id');
            $table->boolean('is_chair')->default(false);
            $table->string('vote')->nullable();
            $table->timestamp('attended_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['case_id', 'committee_pool_member_id'], 'attendance_case_member_unique');
        });

        Schema::create('appeal_trackings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->date('window_start');
            $table->date('window_end');
            $table->boolean('appeal_filed')->default(false);
            $table->date('appeal_filed_at')->nullable();
            $table->text('appeal_outcome')->nullable();
            $table->foreignId('logged_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['case_id']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->string('document_type');
            $table->unsignedInteger('version');
            $table->foreignId('previous_version_id')->nullable();
            $table->foreignId('superseded_by_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_locked')->default(false);
            $table->string('reference_number')->nullable();
            $table->smallInteger('reference_year')->nullable();
            $table->boolean('reference_is_manual')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable();
            $table->foreignId('second_reviewed_by')->nullable();
            $table->timestamp('second_reviewed_at')->nullable();
            $table->string('drafted_by');
            $table->string('penalty_type')->nullable();
            $table->string('pdf_file_path')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['case_id', 'document_type', 'version']);
            $table->unique(['company_id', 'reference_year', 'reference_number']);
        });

        Schema::create('document_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('document_id');
            $table->string('language_code');
            $table->longText('body');
            $table->boolean('is_authoritative')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['document_id', 'language_code']);
        });

        Schema::create('document_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('document_id');
            $table->foreignId('approver_user_id');
            $table->string('role_category');
            $table->string('decision');
            $table->text('comment')->nullable();
            $table->json('languages_present');
            $table->dateTime('decided_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('case_id');
            $table->foreignId('document_id')->nullable();
            $table->string('purpose');
            $table->string('signer_name');
            $table->string('signer_role');
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('committee_pool_member_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('witness_1_name')->nullable();
            $table->string('witness_2_name')->nullable();
            $table->string('attempt_location')->nullable();
            $table->string('attempted_by_name')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('presence_check')->nullable();
            $table->timestamp('presence_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('citations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('document_id');
            $table->foreignId('legal_unit_id');
            $table->text('claim_sentence');
            $table->string('relevance_check')->nullable();
            $table->text('relevance_reason')->nullable();
            $table->boolean('overridden')->default(false);
            $table->foreignId('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
        });

        Schema::create('company_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('sequence_key');
            $table->smallInteger('year')->default(0);
            $table->unsignedInteger('next_value');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'sequence_key', 'year']);
        });

        Schema::create('laws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->string('kind');
            $table->string('name');
            $table->string('number')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('source_file_path');
            $table->date('effective_from')->nullable();
            $table->date('repealed_at')->nullable();
            $table->string('approval_status')->default('pending');
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('law_id');
            $table->foreignId('company_id')->nullable();
            $table->unsignedInteger('version');
            $table->foreignId('previous_version_id')->nullable();
            $table->foreignId('superseded_by_id')->nullable();
            $table->string('article_number');
            $table->string('clause_path')->nullable();
            $table->text('official_text');
            $table->integer('source_page')->nullable();
            $table->text('extraction_a')->nullable();
            $table->text('extraction_b')->nullable();
            $table->string('status')->default('unverified');
            $table->foreignId('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('law_jurisdictions', function (Blueprint $table): void {
            $table->foreignId('law_id')->constrained()->restrictOnDelete();
            $table->string('jurisdiction');
            $table->primary(['law_id', 'jurisdiction']);
        });

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id');
            $table->foreignId('branch_id');
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'user_id', 'branch_id']);
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('locations', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Schema::table('committee_mandates', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('committee_pool_members', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'mandate_id'])->references(['company_id', 'id'])->on('committee_mandates')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign('reporter_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['company_id', 'alleged_responsible_employee_id'], 'fk_report_responsible_employee')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
        });
        Schema::table('report_witnesses', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'violation_report_id'])->references(['company_id', 'id'])->on('violation_reports')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
        Schema::table('cases', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'violation_report_id'])->references(['company_id', 'id'])->on('violation_reports')->restrictOnDelete();
            $table->foreign('law_version_id')->references('id')->on('laws')->restrictOnDelete();
            $table->foreign('opened_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_hr_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['company_id', 'alleged_responsible_employee_id'], 'fk_case_responsible_employee')->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign('legal_review_requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'committee_mandate_id'])->references(['company_id', 'id'])->on('committee_mandates')->restrictOnDelete();
            $table->foreign('employee_response_checked_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('case_witnesses', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
        });
        Schema::table('case_events', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('case_links', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'linked_case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
        });
        Schema::table('committee_session_attendances', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'committee_pool_member_id'], 'fk_attendance_pool_member')->references(['company_id', 'id'])->on('committee_pool_members')->restrictOnDelete();
        });
        Schema::table('appeal_trackings', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign('logged_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'previous_version_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign(['company_id', 'superseded_by_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('second_reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('document_contents', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
        });
        Schema::table('document_approvals', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign('approver_user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_id'])->references(['company_id', 'id'])->on('cases')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->foreign(['company_id', 'committee_pool_member_id'], 'fk_signature_pool_member')->references(['company_id', 'id'])->on('committee_pool_members')->restrictOnDelete();
        });
        Schema::table('citations', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign('legal_unit_id')->references('id')->on('legal_units')->restrictOnDelete();
            $table->foreign('overridden_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('company_sequences', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::table('laws', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('legal_units', function (Blueprint $table): void {
            $table->foreign('law_id')->references('id')->on('laws')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('previous_version_id')->references('id')->on('legal_units')->restrictOnDelete();
            $table->foreign('superseded_by_id')->references('id')->on('legal_units')->restrictOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('branch_user', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['employee_id']));
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('law_jurisdictions');
        Schema::dropIfExists('legal_units');
        Schema::dropIfExists('laws');
        Schema::dropIfExists('company_sequences');
        Schema::dropIfExists('citations');
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('document_contents');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('appeal_trackings');
        Schema::dropIfExists('committee_session_attendances');
        Schema::dropIfExists('case_links');
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('case_witnesses');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('report_witnesses');
        Schema::dropIfExists('violation_reports');
        Schema::dropIfExists('committee_pool_members');
        Schema::dropIfExists('committee_mandates');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('branches');
        Schema::enableForeignKeyConstraints();
    }
};
