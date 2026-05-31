<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups a module under a parent on the workspace settings page and drives the
 * enable/disable cascade: `parent_key` names the parent module's `key` (e.g.
 * equipment_category.parent_key = 'equipment'). Null = a top-level module. A
 * loose string reference (like `morph_alias`), not a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('parent_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('parent_key');
        });
    }
};
