<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The editable email wrapper/branding (colours, header, footer, font) shared
 * by every email. Single row — use MailLayout::current(). Editing it from the
 * dashboard re-compiles all templates (see RecompileEmailTemplatesJob).
 *
 * @property string $brand_color
 * @property string $button_color
 * @property string $body_bg
 * @property string $card_bg
 * @property string $text_color
 * @property string $heading_color
 * @property string $footer_color
 * @property string $font_family
 * @property string $header_mode
 * @property string|null $header_logo_url
 * @property string $footer_text
 */
class MailLayout extends Model
{
    protected $fillable = [
        'brand_color',
        'button_color',
        'body_bg',
        'card_bg',
        'text_color',
        'heading_color',
        'footer_color',
        'font_family',
        'header_mode',
        'header_logo_url',
        'footer_text',
    ];

    public static function current(): self
    {
        $layout = static::query()->firstOrCreate([]);

        // firstOrCreate returns the in-memory instance after an INSERT, so the
        // DB-default columns (brand_color, footer_text, …) aren't hydrated on
        // first creation. Reload once so the very first email rendered against
        // a fresh database still gets the real branding, not empty strings.
        if ($layout->wasRecentlyCreated) {
            $layout->refresh();
        }

        return $layout;
    }
}
