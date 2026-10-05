<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->string('gender')->default('unspecified'));
        DB::statement("ALTER TABLE `employees` ADD CONSTRAINT `employees_gender_check` CHECK (`gender` IN ('male', 'female', 'unspecified'))");
        Schema::create('company_letterheads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('language_code', 8);
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('address_lines')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('footer_text')->default('CONFIDENTIAL');
            $table->string('logo_path')->nullable();
            $table->char('logo_sha256', 64)->nullable();
            $table->string('header_image_path')->nullable();
            $table->char('header_image_sha256', 64)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->foreignId('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'language_code', 'version']);
        });
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type');
            $table->string('language_code', 8);
            $table->unsignedInteger('version');
            $table->json('blocks');
            $table->json('options');
            $table->foreignId('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'document_type', 'language_code', 'version'], 'template_version_unique');
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->json('render_snapshot')->nullable();
            $table->longText('rendered_html')->nullable();
            $table->string('pdf_status')->nullable();
            $table->text('pdf_error')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
        });
        DB::statement("ALTER TABLE `documents` ADD CONSTRAINT `documents_pdf_status_check` CHECK (`pdf_status` IS NULL OR `pdf_status` IN ('pending', 'ready', 'failed'))");
        Schema::table('document_contents', fn (Blueprint $table) => $table->text('secondary_body')->nullable());

        foreach (DB::table('companies')->get() as $company) {
            $logoPath = null;
            $logoSha = null;
            if ($company->letterhead_logo_path && Storage::disk('local')->exists($company->letterhead_logo_path)) {
                $bytes = Storage::disk('local')->get($company->letterhead_logo_path);
                $logoSha = hash('sha256', $bytes);
                $extension = strtolower(pathinfo($company->letterhead_logo_path, PATHINFO_EXTENSION));
                if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                    $logoPath = "letterheads/{$company->id}/{$logoSha}.{$extension}";
                    if (! Storage::disk('local')->put($logoPath, $bytes)) {
                        throw new RuntimeException('Cannot backfill company letterhead logo.');
                    }
                } else {
                    $logoSha = null;
                }
            }
            foreach (['ar', 'en'] as $language) {
                DB::table('company_letterheads')->insert([
                    'company_id' => $company->id, 'language_code' => $language, 'version' => 1,
                    'name' => $company->name, 'address_lines' => $company->letterhead_address,
                    'phone' => $company->letterhead_phone, 'email' => $company->letterhead_email,
                    'footer_text' => 'CONFIDENTIAL', 'logo_path' => $logoPath,
                    'logo_sha256' => $logoSha, 'needs_review' => $language === 'en',
                    'created_at' => now(),
                ]);
            }
        }
        $this->createTriggers(true);
    }

    public function down(): void
    {
        $this->createTriggers(false);
        Schema::table('document_contents', fn (Blueprint $table) => $table->dropColumn('secondary_body'));
        DB::statement('ALTER TABLE `documents` DROP CHECK `documents_pdf_status_check`');
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['render_snapshot', 'rendered_html', 'pdf_status', 'pdf_error', 'pdf_sha256']));
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('company_letterheads');
        DB::statement('ALTER TABLE `employees` DROP CHECK `employees_gender_check`');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('gender'));
    }

    private function createTriggers(bool $current): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `immutable_documents_update`');
        foreach (['insert', 'update', 'delete'] as $action) {
            DB::unprepared("DROP TRIGGER IF EXISTS `immutable_document_contents_{$action}`");
        }
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_insert` BEFORE INSERT ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_update` BEFORE UPDATE ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = NEW.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        DB::unprepared("CREATE TRIGGER `immutable_document_contents_delete` BEFORE DELETE ON `document_contents` FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM `documents` WHERE `id` = OLD.`document_id` AND `is_locked` = 1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: parent document is locked'; END IF; END");
        foreach (['company_letterheads', 'document_templates'] as $table) {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $action) {
                DB::unprepared("DROP TRIGGER IF EXISTS `immutable_{$table}_{$suffix}`");
                if ($current) {
                    DB::unprepared("CREATE TRIGGER `immutable_{$table}_{$suffix}` BEFORE {$action} ON `{$table}` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: versioned document setting'; END");
                }
            }
        }
        $protected = ['id', 'created_at', 'company_id', 'case_id', 'document_type', 'version', 'previous_version_id',
            'correction_reason', 'is_locked', 'reference_number', 'reference_year', 'reference_is_manual',
            'issued_at', 'issued_by', 'second_reviewed_by', 'second_reviewed_at', 'drafted_by', 'penalty_type',
            'created_by', 'leave_confirmed_by', 'leave_confirmed_at'];
        if ($current) {
            array_push($protected, 'render_snapshot', 'rendered_html');
        }
        $checks = array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $protected);
        $checks[] = "(NOT (NEW.`status` <=> OLD.`status`) AND NOT (OLD.`status` = 'issued' AND NEW.`status` = 'superseded'))";
        foreach ($current ? ['superseded_by_id', 'pdf_file_path', 'pdf_sha256'] : ['superseded_by_id', 'pdf_file_path'] as $column) {
            $checks[] = "(NOT (NEW.`{$column}` <=> OLD.`{$column}`) AND NOT (OLD.`{$column}` IS NULL AND NEW.`{$column}` IS NOT NULL))";
        }
        if ($current) {
            $checks[] = "(NOT (NEW.`pdf_status` <=> OLD.`pdf_status`) AND NOT ((OLD.`pdf_status` IS NULL AND NEW.`pdf_status` = 'pending') OR (OLD.`pdf_status` = 'pending' AND NEW.`pdf_status` IN ('failed', 'ready')) OR (OLD.`pdf_status` = 'failed' AND NEW.`pdf_status` IN ('pending', 'ready'))))";
            $checks[] = "(NOT (NEW.`pdf_error` <=> OLD.`pdf_error`) AND NOT (NEW.`pdf_status` IN ('pending', 'failed', 'ready') AND OLD.`pdf_status` IN ('pending', 'failed')))";
            $checks[] = "(NEW.`pdf_status` = 'ready' AND (NEW.`pdf_file_path` IS NULL OR NEW.`pdf_sha256` IS NULL))";
            $checks[] = '((NEW.`pdf_file_path` IS NULL) <> (NEW.`pdf_sha256` IS NULL))';
            $checks[] = "(NEW.`pdf_file_path` IS NOT NULL AND NOT (NEW.`pdf_status` <=> 'ready'))";
            $mutable = ['status', 'superseded_by_id', 'pdf_file_path', 'pdf_sha256', 'pdf_status', 'pdf_error'];
        } else {
            $mutable = ['status', 'superseded_by_id', 'pdf_file_path'];
        }
        $same = implode(' AND ', array_map(fn (string $column) => "(NEW.`{$column}` <=> OLD.`{$column}`)", $mutable));
        $checks[] = "({$same})";
        $checks[] = "(NOT (NEW.`updated_at` <=> OLD.`updated_at`) AND {$same})";
        $condition = implode(' OR ', $checks);
        DB::unprepared("CREATE TRIGGER `immutable_documents_update` BEFORE UPDATE ON `documents` FOR EACH ROW BEGIN IF OLD.`is_locked` = 1 AND ({$condition}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: locked document'; END IF; END");
    }
};
