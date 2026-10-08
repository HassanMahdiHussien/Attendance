<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('witness_scan_attachment_id')->nullable();
            $table->foreign(['company_id', 'witness_scan_attachment_id'], 'signatures_witness_scan_fk')
                ->references(['company_id', 'id'])->on('attachments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropForeign('signatures_witness_scan_fk');
            $table->dropIndex('signatures_witness_scan_fk');
            $table->dropColumn('witness_scan_attachment_id');
        });
    }
};
