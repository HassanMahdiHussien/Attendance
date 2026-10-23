<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('absence_minutes')->default(480);
            $table->json('shift_starts')->default(new Expression('(JSON_ARRAY("00:00", "08:00", "16:00"))'));
            $table->unsignedSmallInteger('shift_minutes')->default(480);
            $table->unsignedSmallInteger('shift_break_minutes')->default(30);
            $table->unsignedSmallInteger('shift_min_presence_minutes')->default(480);
            $table->json('shift_rest_days')->default(new Expression('(JSON_ARRAY(5))'));
            $table->unsignedSmallInteger('security_min_presence_minutes')->default(480);
            $table->unsignedSmallInteger('inference_tolerance_minutes')->default(120);
        });

        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->time('shift_start')->nullable();
            $table->string('shift_source')->nullable();
            $table->string('shift_confidence')->nullable();
            $table->json('shift_evidence')->nullable();
        });

        Schema::table('attendance_day_decisions', function (Blueprint $table): void {
            // Decision kinds are stored as strings, so pick_shift needs only its chosen time.
            $table->time('shift_start')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_day_decisions', function (Blueprint $table): void {
            $table->dropColumn('shift_start');
        });

        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropColumn(['shift_start', 'shift_source', 'shift_confidence', 'shift_evidence']);
        });

        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->dropColumn([
                'absence_minutes', 'shift_starts', 'shift_minutes', 'shift_break_minutes',
                'shift_min_presence_minutes', 'shift_rest_days', 'security_min_presence_minutes',
                'inference_tolerance_minutes',
            ]);
        });
    }
};
