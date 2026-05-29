<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalize file ownership from per-user to polymorphic so any model
 * (Building, Workspace, etc.) can own a file tree. The old `user_id` column
 * stays in place as the "uploaded by" relationship — semantically distinct
 * from "owned by" — and `scope` stays for back-compat with code paths that
 * still filter on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_items', function (Blueprint $table): void {
            $table->string('owner_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
        });

        // Backfill: scope=personal rows are owned by their user, scope=company
        // rows are owned by the tenant. user_id keeps its current value and
        // now reads as "uploader/creator".
        DB::connection((string) config('workspaces.database.central_connection'))
            ->table('file_items')
            ->where('scope', 'personal')
            ->update([
                'owner_type' => User::class,
                'owner_id' => DB::raw('user_id'),
            ]);

        DB::connection((string) config('workspaces.database.central_connection'))
            ->table('file_items')
            ->where('scope', 'company')
            ->update([
                'owner_type' => Workspace::class,
                'owner_id' => DB::raw('workspace_id'),
            ]);

        // Defensive: any row missing scope falls back to user-owned so the
        // NOT NULL tightening below doesn't crash on legacy data.
        DB::connection((string) config('workspaces.database.central_connection'))
            ->table('file_items')
            ->whereNull('owner_type')
            ->update([
                'owner_type' => User::class,
                'owner_id' => DB::raw('user_id'),
            ]);

        // Last line of defence: refuse to tighten NOT NULL while any row
        // would violate it. user_id and workspace_id were already NOT NULL on
        // the existing schema so this should never fire — but corrupt /
        // legacy data shouldn't crash with an opaque DB error mid-migrate.
        $orphaned = DB::connection((string) config('workspaces.database.central_connection'))
            ->table('file_items')
            ->where(function ($q): void {
                $q->whereNull('owner_type')->orWhereNull('owner_id');
            })
            ->count();

        if ($orphaned > 0) {
            throw new RuntimeException(
                "Refusing to tighten owner_type/owner_id NOT NULL: {$orphaned} file_items row(s) "
                .'still have NULL owner. Backfill manually before re-running this migration.',
            );
        }

        Schema::table('file_items', function (Blueprint $table): void {
            $table->string('owner_type')->nullable(false)->change();
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();

            $table->index(['workspace_id', 'owner_type', 'owner_id', 'parent_id'], 'file_items_owner_listing_idx');
            $table->index(['workspace_id', 'owner_type', 'owner_id', 'name'], 'file_items_owner_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('file_items', function (Blueprint $table): void {
            $table->dropIndex('file_items_owner_listing_idx');
            $table->dropIndex('file_items_owner_name_idx');
            $table->dropColumn(['owner_type', 'owner_id']);
        });
    }
};
