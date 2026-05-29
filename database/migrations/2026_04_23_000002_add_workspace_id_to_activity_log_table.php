<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp every activity_log row with the workspace (tenant) that was active
 * when the activity fired. Without this, workspace-scoped dashboards that
 * filter activity by "members of this workspace" leak rows from other
 * workspaces the same user also belongs to.
 *
 * Nullable: central-only activities (registering, password reset, profile
 * edit from the picker page) genuinely have no workspace context, and we
 * want to preserve that distinction rather than backfill with a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_log', 'workspace_id')) {
                $table->unsignedBigInteger('workspace_id')->nullable()->after('causer_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            if (Schema::hasColumn('activity_log', 'workspace_id')) {
                $table->dropIndex(['workspace_id']);
                $table->dropColumn('workspace_id');
            }
        });
    }
};
