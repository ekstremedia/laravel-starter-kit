<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform super-user flag. Separate from Spatie roles because Spatie's team
 * schema makes `model_has_roles.team_id` NOT NULL — there's no natural way to
 * represent a "global, not attached to any workspace" role assignment.
 *
 * SuperAdmin is truly platform-level (can enter any workspace, reaches
 * /admin/*), so a boolean column on `users` is both simpler and closer to
 * what it actually is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropIndex(['is_super_admin']);
                $table->dropColumn('is_super_admin');
            }
        });
    }
};
