<?php

use Illuminate\Support\Facades\DB;

test('trigger migration creates, drops, and recreates every trigger', function (): void {
    // DDL runs on a separate connection so RefreshDatabase keeps its test transaction.
    $default = DB::getDefaultConnection();
    config(['database.connections.trigger_lifecycle' => config("database.connections.{$default}")]);
    DB::setDefaultConnection('trigger_lifecycle');
    $path = database_path('migrations/2026_10_04_203000_create_immutability_triggers.php');
    $migration = require $path;
    $names = fn () => DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
        ->where('TRIGGER_NAME', 'like', 'immutable_%')
        ->pluck('TRIGGER_NAME')->sort()->values()->all();
    try {
        $expected = $names();
        expect($expected)->toHaveCount(21);
        try {
            $migration->down();
            expect($names())->toBe([]);
        } finally {
            $migration->up();
        }
        expect($names())->toBe($expected);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('trigger_lifecycle');
    }
});
