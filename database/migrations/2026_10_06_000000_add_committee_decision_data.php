<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'users_company_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // MySQL dropped the implicit index of users.company_id's foreign key when the composite
            // unique index could serve it; restore a plain index before removing the composite one.
            $table->index('company_id', 'users_company_id_foreign');
            $table->dropUnique('users_company_id_unique');
        });
    }
};
