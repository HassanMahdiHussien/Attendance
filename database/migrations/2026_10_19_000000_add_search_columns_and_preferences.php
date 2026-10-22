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

        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable();
        });

        DB::table('employees')->select('id', 'name')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('employees')->where('id', $row->id)->update(['search_name' => SearchText::normalize($row->name)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'search_name']);
            $table->dropColumn('search_name');
        });
    }
};
