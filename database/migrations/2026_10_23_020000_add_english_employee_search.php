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
            $table->string('search_name_en')->default('');
            $table->index(['company_id', 'search_name_en'], 'employees_co_search_en_idx');
        });

        DB::table('employees')->select(['id', 'name_en'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('employees')->where('id', $row->id)->update([
                    'search_name_en' => SearchText::normalize((string) $row->name_en),
                ]);
            }
        });
    }

    public function down(): void
    {
        // MySQL may use the search index to back the company foreign key.
        if (! Schema::hasIndex('employees', ['company_id'])) {
            Schema::table('employees', fn (Blueprint $table) => $table->index('company_id', 'employees_co_fk_idx'));
        }
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_co_search_en_idx');
            $table->dropColumn('search_name_en');
        });
    }
};
