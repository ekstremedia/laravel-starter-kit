<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-specific performance indexes:
 *  - pg_trgm GIN indexes so ILIKE '%term%' search (equipment, users, files,
 *    workspaces, categories) uses an index instead of a sequential scan.
 *  - a unique index on user_settings.user_id (one row per user; read on every
 *    authenticated request).
 *  - a partial index for the unread-notifications badge count.
 *  - workspace+deleted_at indexes for trash queries, and an activity_log
 *    created_at index for the reverse-chronological admin feed.
 *
 * Postgres-only — the test suite runs on SQLite, where GIN/trigram/partial
 * indexes don't apply, so up()/down() are a no-op there.
 */
return new class extends Migration
{
    /** CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    /** @return array<int, array{name: string, create: string}> */
    private function indexes(): array
    {
        return [
            // Trigram search indexes (need the pg_trgm extension).
            ['name' => 'equipment_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS equipment_name_trgm_idx ON equipment USING gin (name gin_trgm_ops)'],
            ['name' => 'equipment_serial_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS equipment_serial_trgm_idx ON equipment USING gin (serial gin_trgm_ops)'],
            ['name' => 'equipment_categories_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS equipment_categories_name_trgm_idx ON equipment_categories USING gin (name gin_trgm_ops)'],
            ['name' => 'users_first_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS users_first_name_trgm_idx ON users USING gin (first_name gin_trgm_ops)'],
            ['name' => 'users_last_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS users_last_name_trgm_idx ON users USING gin (last_name gin_trgm_ops)'],
            ['name' => 'users_email_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS users_email_trgm_idx ON users USING gin (email gin_trgm_ops)'],
            ['name' => 'file_items_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS file_items_name_trgm_idx ON file_items USING gin (name gin_trgm_ops)'],
            ['name' => 'workspaces_name_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS workspaces_name_trgm_idx ON workspaces USING gin (name gin_trgm_ops)'],
            ['name' => 'workspaces_slug_trgm_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS workspaces_slug_trgm_idx ON workspaces USING gin (slug gin_trgm_ops)'],

            // One settings row per user; indexes the per-request lookup.
            ['name' => 'user_settings_user_id_unique', 'create' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_settings_user_id_unique ON user_settings (user_id)'],

            // Unread-notifications badge count (filtered every page load).
            ['name' => 'notifications_unread_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_unread_idx ON notifications (notifiable_type, notifiable_id) WHERE read_at IS NULL'],

            // Trash / soft-delete scans.
            ['name' => 'equipment_workspace_deleted_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS equipment_workspace_deleted_idx ON equipment (workspace_id, deleted_at)'],
            ['name' => 'equipment_categories_workspace_deleted_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS equipment_categories_workspace_deleted_idx ON equipment_categories (workspace_id, deleted_at)'],

            // Reverse-chronological activity feed (admin dashboard).
            ['name' => 'activity_log_created_at_idx', 'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS activity_log_created_at_idx ON activity_log (created_at DESC)'],
        ];
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->indexes() as $index) {
            DB::statement($index['create']);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexes() as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index['name']}");
        }
    }
};
