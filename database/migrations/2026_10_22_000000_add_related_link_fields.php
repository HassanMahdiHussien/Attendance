<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_links', function (Blueprint $table): void {
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreign(['company_id', 'created_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('case_link_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('case_link_id');
            $table->string('kind');
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'id']);
            $table->unique(['case_link_id', 'kind']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'case_link_id'])->references(['company_id', 'id'])->on('case_links')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared('CREATE TRIGGER immutable_case_link_annotations_'.strtolower($operation).
                " BEFORE {$operation} ON case_link_annotations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: case link annotations are append-only'; END");
            DB::unprepared('CREATE TRIGGER immutable_case_links_'.strtolower($operation).
                " BEFORE {$operation} ON case_links FOR EACH ROW BEGIN IF OLD.link_type = 'related' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'IMMUTABLE: related case links are permanent'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS immutable_case_link_annotations_'.$operation);
            DB::unprepared('DROP TRIGGER IF EXISTS immutable_case_links_'.$operation);
        }
        Schema::dropIfExists('case_link_annotations');
        Schema::table('case_links', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'created_by_user_id']);
            $table->dropColumn('created_by_user_id');
            $table->dropColumn('reason');
        });
    }
};
