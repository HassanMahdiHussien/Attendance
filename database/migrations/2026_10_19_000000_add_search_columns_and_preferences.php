<?php

use App\Support\Search\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('search_name')->default('');
            $table->index(['company_id', 'search_name']);
        });
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->string('search_text', 512)->default('');
            $table->index(['company_id', 'search_text']);
        });
        Schema::table('email_drafts', function (Blueprint $table): void {
            $table->string('search_subject')->default('');
            $table->index(['company_id', 'search_subject']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable();
        });

        DB::table('employees')->select('id', 'name')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('employees')->where('id', $row->id)->update(['search_name' => SearchText::normalize($row->name)]);
            }
        });
        DB::table('violation_reports')->select('id', 'violation_type', 'place_freetext')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('violation_reports')->where('id', $row->id)->update(['search_text' => SearchText::normalize($row->violation_type.' '.($row->place_freetext ?? ''))]);
            }
        });
        DB::table('email_drafts')->select('id', 'subject')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('email_drafts')->where('id', $row->id)->update(['search_subject' => SearchText::normalize($row->subject)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('email_drafts', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'search_subject']);
            $table->dropColumn('search_subject');
        });
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'search_text']);
            $table->dropColumn('search_text');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'search_name']);
            $table->dropColumn('search_name');
        });
    }
};
