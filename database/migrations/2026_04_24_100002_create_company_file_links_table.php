<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_file_links', function (Blueprint $table) {
            $table->id();
            // Tenant owning the share. Denormalized from the target
            // company_parent_id for efficient root-level listing.
            $table->foreignId('workspace_id')->constrained('tenants')->cascadeOnDelete();
            // The personal file being exposed to the company. Must be
            // scope='personal' and type='file' — enforced in the controller.
            $table->foreignId('file_item_id')->constrained('file_items')->cascadeOnDelete();
            // Target folder in the company tree (nullable = company root).
            // Must be scope='company' and type='folder' — enforced in the
            // controller. nullOnDelete means dropping a company folder
            // demotes its links to the company root rather than removing
            // them; that preserves the personal files' shared state when
            // an admin reorganises the company folder tree.
            $table->foreignId('company_parent_id')->nullable()
                ->constrained('file_items')->nullOnDelete();
            // The member who shared it — usually, but not necessarily, the
            // file's owner. Kept nullable in case the user is later deleted.
            $table->foreignId('shared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One link per (tenant, file) — re-linking a file moves it to a
            // different company folder instead of duplicating the row.
            $table->unique(['workspace_id', 'file_item_id']);
            $table->index(['workspace_id', 'company_parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_file_links');
    }
};
