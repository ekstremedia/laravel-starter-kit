<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace "About" profile page (headline/about/location/website) was
 * removed, so drop the now-unused columns. No UI or logic references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['headline', 'about', 'location', 'website']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('headline', 160)->nullable();
            $table->text('about')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('website')->nullable();
        });
    }
};
