<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module registry — a platform-global list of optional domain modules
 * (Equipment today; Cars, Medicines… tomorrow). The `enabled` flag drives route
 * registration (routes/workspace.php) and sidebar visibility; `morph_alias`
 * links a module to its file-owning entity for record/storage statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            // The Eloquent morph alias of this module's file-owning entity
            // (e.g. 'equipment'), used to total record/storage stats. Null for
            // modules that own no files.
            $table->string('morph_alias')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
