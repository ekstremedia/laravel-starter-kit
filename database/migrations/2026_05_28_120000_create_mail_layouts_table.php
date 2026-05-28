<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row table holding the editable wrapper/branding shared by every
 * email (the MJML layout). Defaults mirror the previously-hardcoded values in
 * resources/views/mjml/layout.blade.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('brand_color', 9)->default('#4f46e5');
            $table->string('button_color', 9)->default('#4f46e5');
            $table->string('body_bg', 9)->default('#f3f4f6');
            $table->string('card_bg', 9)->default('#ffffff');
            $table->string('text_color', 9)->default('#374151');
            $table->string('heading_color', 9)->default('#111827');
            $table->string('footer_color', 9)->default('#9ca3af');
            $table->string('font_family')->default('Arial, Helvetica, sans-serif');
            $table->string('header_mode', 10)->default('text'); // text | image
            $table->string('header_logo_url')->nullable();
            $table->string('footer_text', 500)->default('© {{ year }} {{ app_name }}. All rights reserved.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_layouts');
    }
};
