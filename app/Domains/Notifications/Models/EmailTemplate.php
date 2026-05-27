<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Services\MjmlCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;

class EmailTemplate extends Model
{
    protected $fillable = [
        'slug',
        'locale',
        'name',
        'subject',
        'heading',
        'body',
        'action_text',
        'action_url',
        'variables',
        'compiled_html',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }

    /**
     * Find a template by slug and locale, falling back to English.
     */
    public static function forSlug(string $slug, string $locale): ?self
    {
        return static::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first()
            ?? static::query()
                ->where('slug', $slug)
                ->where('locale', 'en')
                ->first();
    }

    /**
     * Compile the template content into the MJML layout and cache the HTML.
     */
    public function compile(): void
    {
        $mjml = View::make('mjml.layout', [
            'heading' => $this->heading ?? '',
            'body' => $this->body ?? '',
            'actionText' => $this->action_text,
            'actionUrl' => $this->action_url,
        ])->render();

        $compiler = app(MjmlCompiler::class);
        $this->compiled_html = $compiler->compile($mjml);
        $this->save();
    }

    /**
     * Render the compiled HTML with variable interpolation.
     *
     * @param  array<string, string>  $data
     */
    public function render(array $data): string
    {
        $html = $this->compiled_html ?? '';

        foreach ($data as $key => $value) {
            $html = str_replace('{{ '.$key.' }}', e($value), $html);
        }

        return $html;
    }

    /**
     * Interpolate variables in a string (used for subjects etc.).
     *
     * @param  array<string, string>  $data
     */
    public function interpolateSubject(array $data): string
    {
        $subject = $this->subject ?? '';

        foreach ($data as $key => $value) {
            $subject = str_replace('{{ '.$key.' }}', $value, $subject);
        }

        return $subject;
    }

    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }
}
