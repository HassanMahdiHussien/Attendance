<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('branch_id');
            $table->string('name');
            $table->string('normalized_name');
            $table->timestamps();
            $table->unique(['company_id', 'normalized_name']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_aliases');
    }
};
