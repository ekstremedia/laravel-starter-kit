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
            // The Eloquent morph alias of this module's entity (e.g. 'equipment'),
            // used to total record/storage stats. Null for modules with no entity.
            $table->string('morph_alias')->nullable();
            // What the module's CODE ships, seeded from the module and NOT
            // user-editable: e.g. {"files": true, "log": true}. The ceiling for
            // `features` — a capability the code lacks can never be toggled on.
            $table->json('capabilities')->nullable();
            // Runtime feature toggles, super-admin editable in /admin/modules,
            // defaulting to `capabilities`: e.g. {"files": true, "log": false}.
            // Per-workspace overrides live in `workspace_module_features`.
            $table->json('features')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
