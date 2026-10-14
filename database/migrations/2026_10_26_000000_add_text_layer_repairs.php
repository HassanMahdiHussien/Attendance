<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laws', function (Blueprint $table): void {
            $table->json('text_layer_fixes')->nullable();
        });
        Schema::table('legal_import_pages', function (Blueprint $table): void {
            $table->json('text_repairs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legal_import_pages', fn (Blueprint $table) => $table->dropColumn('text_repairs'));
        Schema::table('laws', fn (Blueprint $table) => $table->dropColumn('text_layer_fixes'));
    }
};
