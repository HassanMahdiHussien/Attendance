<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('internal_regulation_answer', 3)->nullable();
            $table->timestamp('internal_regulation_answered_at')->nullable();
            $table->foreignId('internal_regulation_answered_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('companies')->whereExists(fn ($query) => $query->selectRaw('1')->from('cases')
            ->whereColumn('cases.company_id', 'companies.id'))
            ->update([
                'internal_regulation_answer' => DB::raw("IF(has_internal_regulation = 1, 'yes', 'no')"),
                'internal_regulation_answered_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropConstrainedForeignId('internal_regulation_answered_by'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['internal_regulation_answer', 'internal_regulation_answered_at']));
    }
};
