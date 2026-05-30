<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Equipment ("Utstyr") module — the reference file-owning entity (think
 * cars, equipment, medicines — any trackable thing that owns documents). Lives
 * on the central schema alongside users/workspaces/file_items. It is the
 * template for future modules; register new ones in the `modules` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            // A real relation rather than a free-text string: each item is filed
            // under one EquipmentCategory (the demo belongsTo). Nulled (not
            // cascaded) when the category is deleted, so the item survives as
            // "uncategorised". The categories table is created just before this one.
            $table->foreignId('equipment_category_id')->nullable()->constrained('equipment_categories')->nullOnDelete();
            $table->string('serial')->nullable();
            $table->text('notes')->nullable();
            // The "main" document used as the row thumbnail / cover image. Null
            // falls back to the first previewable file. Nulled (not cascaded)
            // when the referenced file is deleted so the item itself survives.
            $table->foreignId('cover_file_item_id')->nullable()->constrained('file_items')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'name']);
        });

        Schema::table('app_settings', function (Blueprint $table): void {
            // Global default storage cap for file-owning entities that opt into
            // per-row quotas (via HasFileQuota). Equipment itself does not, but
            // the column is shared infra for future modules that might.
            // null = unlimited; -1 = explicit unlimited; N>=0 = byte cap.
            $table->bigInteger('default_entity_storage_bytes')->nullable()->after('default_personal_storage_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn('default_entity_storage_bytes');
        });

        Schema::dropIfExists('equipment');
    }
};
