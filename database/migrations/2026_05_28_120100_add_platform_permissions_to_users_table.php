<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grantable platform-level capabilities (e.g. "manage_email_templates").
 *
 * Platform access is modelled as a column rather than a Spatie permission: the
 * package's team schema forces model_has_permissions.team_id non-null, so a
 * global (team-less) permission isn't representable — the same reason
 * super-admin is a boolean column. SuperAdmins bypass these via Gate::before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('platform_permissions')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_permissions');
        });
    }
};
