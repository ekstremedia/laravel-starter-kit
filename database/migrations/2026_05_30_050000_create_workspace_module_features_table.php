<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace overrides for a module's optional features (files / log). A row
 * exists only when a workspace admin has overridden the platform default; absent
 * = inherit the platform `modules.features`. Mirrors the storage-quota
 * inherit/override pattern. Platform-global enable/disable still lives on
 * `modules.enabled` and is not overridable per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_module_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            // Partial override map, e.g. {"files": false}. Keys absent here fall
            // back to the platform feature, which falls back to the capability.
            $table->json('features')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_module_features');
    }
};
