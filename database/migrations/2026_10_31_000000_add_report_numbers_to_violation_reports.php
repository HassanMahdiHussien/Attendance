<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->unsignedInteger('report_number')->nullable()->after('id');
            $table->boolean('place_unknown')->default(false);
            $table->boolean('when_unknown')->default(false);
            $table->unique(['company_id', 'report_number']);
        });

        foreach (DB::table('violation_reports')->select('company_id')->distinct()->pluck('company_id') as $companyId) {
            $number = 0;
            foreach (DB::table('violation_reports')->where('company_id', $companyId)->orderBy('id')->pluck('id') as $id) {
                DB::table('violation_reports')->where('id', $id)->update(['report_number' => ++$number]);
            }
            DB::table('company_sequences')->insertOrIgnore([
                'company_id' => $companyId, 'sequence_key' => 'report', 'year' => 0,
                'next_value' => $number + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_sequences')->where('company_id', $companyId)->where('sequence_key', 'report')
                ->where('year', 0)->update(['next_value' => $number + 1]);
        }
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->unsignedInteger('report_number')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        DB::table('company_sequences')->where('sequence_key', 'report')->delete();
        Schema::table('violation_reports', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'report_number']);
            $table->dropColumn('report_number');
            $table->dropColumn(['place_unknown', 'when_unknown']);
        });
    }
};
