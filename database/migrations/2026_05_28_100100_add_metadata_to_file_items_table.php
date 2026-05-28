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
            // Normalized file metadata (EXIF/GPS/dimensions/codec/page count)
            // extracted at upload by FileMetadataExtractor. Null = not yet
            // extracted (legacy rows are filled lazily by the details endpoint).
            $table->json('metadata')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('file_items', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
