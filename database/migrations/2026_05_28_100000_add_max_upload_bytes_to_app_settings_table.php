<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Per-file upload ceiling enforced on the files.* validation rule.
            // Admin-tunable from /admin/settings, but always clamped server-side
            // to the running PHP upload_max_filesize/post_max_size ceiling.
            // 50 MB default mirrors the previous FILES_MAX_UPLOAD_KB (51200 KB)
            // config fallback.
            $table->unsignedBigInteger('max_upload_bytes')->default(50 * 1024 * 1024);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('max_upload_bytes');
        });
    }
};
