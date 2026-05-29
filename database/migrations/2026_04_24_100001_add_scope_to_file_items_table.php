<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_items', function (Blueprint $table) {
            // 'personal' (default, existing rows) or 'company'. Personal items
            // live in the user's /files tree; company items live in the
            // tenant's /files/company tree. parent_id always references the
            // same scope — cross-scope nesting is not allowed.
            $table->string('scope', 16)->default('personal');
            $table->index(['workspace_id', 'scope', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('file_items', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'scope', 'parent_id']);
            $table->dropColumn('scope');
        });
    }
};
