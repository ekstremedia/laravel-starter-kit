<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Separate from files_feature_enabled so a workspace can keep
            // personal files while disabling the shared-company area.
            $table->boolean('company_files_enabled')->default(false);
            // Signed so we can encode an explicit "unlimited" override as -1
            // distinct from null ("inherit from app default"). 0 = blocked
            // (no company uploads), N > 0 = cap.
            $table->bigInteger('storage_quota_bytes')->nullable();
            // Denormalized running total for the company files bucket.
            // Maintained by StorageUsageService::recomputeForTenant.
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            // Per-workspace default personal quota for members. Null = inherit
            // from the global AppSetting default. -1 = explicit unlimited for
            // this workspace (overrides any global cap). See
            // StorageUsageService::effectivePersonalQuota for the full
            // 3-tier resolution order.
            $table->bigInteger('default_member_storage_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'company_files_enabled',
                'storage_quota_bytes',
                'storage_used_bytes',
                'default_member_storage_bytes',
            ]);
        });
    }
};
