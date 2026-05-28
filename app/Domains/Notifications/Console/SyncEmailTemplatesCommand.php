<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Models\EmailTemplate;
use Illuminate\Console\Command;

/**
 * Syncs the `email_templates` table to the `config/mail-templates.php`
 * registry. Dev-owned metadata (name, variables) is always refreshed; the
 * editable copy (subject/heading/body/action_*) is only seeded when a row is
 * first created, so admin edits in the dashboard are never clobbered.
 */
class SyncEmailTemplatesCommand extends Command
{
    protected $signature = 'mail:sync-templates
        {--fresh : Reset editable content (subject/heading/body/action_*) back to the registry defaults}
        {--prune : Delete template rows whose slug is no longer in the registry}';

    protected $description = 'Sync email_templates with config/mail-templates.php (create missing, refresh metadata, optionally reset/prune)';

    public function handle(): int
    {
        /** @var array<string, array{variables?: list<string>, locales?: array<string, array{name: string, subject: string, heading?: string|null, body: string, action_text?: string|null, action_url?: string|null}>}> $registry */
        $registry = config('mail-templates', []);

        $created = 0;
        $updated = 0;
        $compiled = 0;
        $fresh = (bool) $this->option('fresh');

        foreach ($registry as $slug => $definition) {
            $variables = $definition['variables'] ?? [];

            foreach ($definition['locales'] ?? [] as $locale => $content) {
                /** @var EmailTemplate|null $existing */
                $existing = EmailTemplate::query()->where('slug', $slug)->where('locale', $locale)->first();

                if (! $existing) {
                    $template = EmailTemplate::query()->create([
                        'slug' => $slug,
                        'locale' => $locale,
                        'name' => $content['name'],
                        'subject' => $content['subject'],
                        'heading' => $content['heading'] ?? null,
                        'body' => $content['body'],
                        'action_text' => $content['action_text'] ?? null,
                        'action_url' => $content['action_url'] ?? null,
                        'variables' => $variables,
                    ]);
                    $template->compile();
                    $created++;
                    $compiled++;

                    continue;
                }

                // Always keep dev-owned metadata in sync...
                $existing->name = $content['name'];
                $existing->variables = $variables;

                // ...but only overwrite editable copy when explicitly resetting.
                if ($fresh) {
                    $existing->fill([
                        'subject' => $content['subject'],
                        'heading' => $content['heading'] ?? null,
                        'body' => $content['body'],
                        'action_text' => $content['action_text'] ?? null,
                        'action_url' => $content['action_url'] ?? null,
                    ]);
                }

                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }

                // Compile if content changed or the cache is missing.
                if ($fresh || empty($existing->compiled_html) || $existing->wasChanged(['heading', 'body', 'action_text', 'action_url'])) {
                    $existing->compile();
                    $compiled++;
                }
            }
        }

        $pruned = 0;
        if ($this->option('prune')) {
            $knownSlugs = array_keys($registry);
            $pruned = EmailTemplate::query()->whereNotIn('slug', $knownSlugs)->delete();
        }

        $this->info("Email templates synced — created: {$created}, updated: {$updated}, compiled: {$compiled}".($this->option('prune') ? ", pruned: {$pruned}" : ''));

        return self::SUCCESS;
    }
}
