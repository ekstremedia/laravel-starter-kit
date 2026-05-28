<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo entity for the polymorphic file system: an "Asset" register (think
 * cars, equipment, medicines — any trackable thing that owns documents).
 * Lives on the central schema alongside users/tenants/file_items. Remove the
 * whole app/Domains/Assets module + this migration to drop the demo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('serial')->nullable();
            $table->text('notes')->nullable();
            // Per-entity storage override. null = inherit the app/entity
            // default; -1 = explicit unlimited; 0 = blocked; N>0 = byte cap.
            // Signed so the -1 sentinel fits (mirrors tenants.storage_quota_bytes).
            $table->bigInteger('file_quota_bytes')->nullable();
            // Denormalized billable bytes, refreshed by StorageUsageService.
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        Schema::table('app_settings', function (Blueprint $table): void {
            // Global default storage cap for file-owning entities (Assets and
            // future Building/Vehicle/etc.) when they set no per-row override.
            // null = unlimited; -1 = explicit unlimited; N>=0 = byte cap.
            $table->bigInteger('default_entity_storage_bytes')->nullable()->after('default_personal_storage_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn('default_entity_storage_bytes');
        });

        Schema::dropIfExists('assets');
    }
};
