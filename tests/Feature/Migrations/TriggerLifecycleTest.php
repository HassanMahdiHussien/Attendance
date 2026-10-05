<?php

use Illuminate\Support\Facades\DB;

// Rolls back and re-applies every trigger migration in order, then checks that each trigger's
// definition is identical. Running a single older migration on its own would silently restore a
// superseded trigger body in the test database.
test('trigger migrations drop and recreate every trigger with identical definitions', function (): void {
    // DDL runs on a separate connection so RefreshDatabase keeps its test transaction.
    $default = DB::getDefaultConnection();
    config(['database.connections.trigger_lifecycle' => config("database.connections.{$default}")]);
    DB::setDefaultConnection('trigger_lifecycle');
    $migrations = array_map(fn (string $file) => require database_path("migrations/{$file}"), [
        '2026_10_04_203000_create_immutability_triggers.php',
        '2026_10_05_011000_add_workflow_triggers.php',
        '2026_10_05_020000_add_document_templates.php',
    ]);
    $triggers = fn () => DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
        ->orderBy('TRIGGER_NAME')
        ->pluck('ACTION_STATEMENT', 'TRIGGER_NAME')->all();

    try {
        $before = $triggers();
        expect($before)->toHaveKey('workflow_cases_status_update')
            ->and($before)->toHaveKey('immutable_documents_update')
            ->and($before['immutable_documents_update'])->toContain('leave_confirmed_by');

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            expect($triggers())->toBe([]);
        } finally {
            foreach ($migrations as $migration) {
                $migration->up();
            }
        }

        expect($triggers())->toBe($before);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('trigger_lifecycle');
    }
});
