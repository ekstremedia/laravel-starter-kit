<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment categories — a workspace-scoped lookup that Equipment belongsTo.
 * This is the demo "related entity": it replaces Equipment's old free-text
 * `category` string with a real relation (EquipmentCategory hasMany Equipment),
 * the pattern future relations (Car → Wheels) follow.
 *
 * It is also the reference "lean" module: it owns NO files (no media/cover),
 * only a name/colour/description and an activity Log. Created BEFORE the
 * equipment table so equipment.equipment_category_id can reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            // A short hex colour (#RRGGBB / #RRGGBBAA) rendered as the row chip;
            // null falls back to a neutral chip at render time.
            $table->string('color', 9)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};
