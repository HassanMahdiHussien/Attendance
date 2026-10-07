<?php

use App\Models\Law;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laws', function (Blueprint $table): void {
            $table->boolean('requires_page_range')->default(false);
        });

        Law::withoutGlobalScopes()->where('kind', 'statute')->where('number', '18')
            ->where('year', 2023)->where('source_file_path', 'docs/laws/gazette-4734-incl-social-security-law-18-2023.pdf')
            ->update(['requires_page_range' => true]);
    }

    public function down(): void
    {
        Schema::table('laws', function (Blueprint $table): void {
            $table->dropColumn('requires_page_range');
        });
    }
};
