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
    // Roll back every migration from the first trigger migration onward, not only the trigger ones:
    // a migration that also creates tables would otherwise be recreated without later column changes.
    $files = collect(glob(database_path('migrations/*.php')))->map(fn (string $path) => basename($path))
        ->filter(fn (string $file) => $file >= '2026_10_04_203000_create_immutability_triggers.php')
        ->sort()->values()->all();
    $migrations = array_map(fn (string $file) => require database_path("migrations/{$file}"), $files);
    $columns = fn () => DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->orderBy('TABLE_NAME')->orderBy('COLUMN_NAME')->get(['TABLE_NAME', 'COLUMN_NAME'])
        ->map(fn ($column) => $column->TABLE_NAME.'.'.$column->COLUMN_NAME)->all();
    $columnsBefore = $columns();
    $triggers = fn () => DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
        ->orderBy('TRIGGER_NAME')
        ->pluck('ACTION_STATEMENT', 'TRIGGER_NAME')->all();

    try {
        $before = $triggers();
        expect($before)->toHaveKey('workflow_cases_status_update')
            ->and($before)->toHaveKey('immutable_documents_update')
            ->and($before['immutable_documents_update'])->toContain('leave_confirmed_by');

        // A failing down() must be reported as itself: re-applying after a partial rollback would
        // otherwise fail with an unrelated "trigger already exists" and hide the real cause.
        $rolledBack = [];
        try {
            foreach (array_reverse($files) as $index => $file) {
                $migrations[array_search($file, $files, true)]->down();
                $rolledBack[] = $file;
            }
            expect($triggers())->toBe([]);
        } catch (Throwable $error) {
            throw new RuntimeException('Rolling back '.(array_reverse($files)[count($rolledBack)] ?? '?')
                .' failed: '.$error->getMessage(), previous: $error);
        } finally {
            foreach (array_reverse($rolledBack) as $file) {
                $migrations[array_search($file, $files, true)]->up();
            }
        }

        expect($triggers())->toBe($before)
            ->and($columns())->toBe($columnsBefore);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('trigger_lifecycle');
    }
});
