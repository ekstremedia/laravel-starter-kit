<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-file size cap for chat attachments, configurable from /admin/settings
 * when chat is enabled. Defaults to 10 MB (the previous hard-coded limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_max_upload_bytes')->default(10 * 1024 * 1024);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('chat_max_upload_bytes');
        });
    }
};
