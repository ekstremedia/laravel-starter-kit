<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('headline', 160)->nullable()->after('name');
            $table->text('about')->nullable()->after('headline');
            $table->string('location', 120)->nullable()->after('about');
            $table->string('website', 255)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['headline', 'about', 'location', 'website']);
        });
    }
};
