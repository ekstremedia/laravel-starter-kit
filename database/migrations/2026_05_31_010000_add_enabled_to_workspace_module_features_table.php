<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace override of a module's enabled state, alongside the existing
 * per-workspace feature overrides on the same row. Nullable: null = inherit the
 * platform `modules.enabled`; true/false = the workspace admin's override.
 * Platform stays the ceiling — a workspace can disable a platform-enabled
 * module, but can't enable one the platform turned off (clamped at resolution).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_module_features', function (Blueprint $table): void {
            $table->boolean('enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_module_features', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};
