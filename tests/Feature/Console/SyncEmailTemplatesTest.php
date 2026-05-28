<?php

use App\Domains\Notifications\Models\EmailTemplate;
use Illuminate\Support\Facades\Config;

it('creates a row for every registry slug and locale', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    $registry = config('mail-templates');
    $expected = collect($registry)->sum(fn ($def) => count($def['locales'] ?? []));

    expect(EmailTemplate::count())->toBe($expected);
});

it('is idempotent — a second sync creates nothing new', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();
    $before = EmailTemplate::count();

    $this->artisan('mail:sync-templates')->assertSuccessful();

    expect(EmailTemplate::count())->toBe($before);
});

it('never clobbers admin-edited content on a normal sync', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    $template = EmailTemplate::forSlug('welcome', 'en');
    $template->update(['subject' => 'Admin edited subject', 'body' => 'Admin body']);

    $this->artisan('mail:sync-templates')->assertSuccessful();

    $template->refresh();
    expect($template->subject)->toBe('Admin edited subject');
    expect($template->body)->toBe('Admin body');
});

it('refreshes dev-owned metadata (variables) even without --fresh', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    $template = EmailTemplate::forSlug('welcome', 'en');
    $template->update(['variables' => ['stale_var']]);

    $this->artisan('mail:sync-templates')->assertSuccessful();

    expect($template->refresh()->variables)->toBe(config('mail-templates.welcome.variables'));
});

it('resets editable content to registry defaults with --fresh', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    $registryBody = config('mail-templates.welcome.locales.en.body');
    $template = EmailTemplate::forSlug('welcome', 'en');
    $template->update(['body' => 'Admin body']);

    $this->artisan('mail:sync-templates', ['--fresh' => true])->assertSuccessful();

    expect($template->refresh()->body)->toBe($registryBody);
});

it('adds a newly declared template (future-proofing) on sync', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    $registry = config('mail-templates');
    $registry['qa-throwaway'] = [
        'variables' => ['user_name'],
        'locales' => [
            'en' => [
                'name' => 'QA Throwaway',
                'subject' => 'Hi {{ user_name }}',
                'heading' => 'Heading',
                'body' => 'Body for {{ user_name }}.',
                'action_text' => null,
                'action_url' => null,
            ],
        ],
    ];
    Config::set('mail-templates', $registry);

    $this->artisan('mail:sync-templates')->assertSuccessful();

    $new = EmailTemplate::forSlug('qa-throwaway', 'en');
    expect($new)->not->toBeNull();
    expect($new->variables)->toBe(['user_name']);
    expect($new->compiled_html)->not->toBeEmpty();
});

it('prunes rows whose slug left the registry only with --prune', function () {
    $this->artisan('mail:sync-templates')->assertSuccessful();

    EmailTemplate::create([
        'slug' => 'orphaned-slug',
        'locale' => 'en',
        'name' => 'Orphan',
        'subject' => 's',
        'body' => 'b',
        'variables' => [],
    ]);

    // A plain sync leaves unknown slugs alone.
    $this->artisan('mail:sync-templates')->assertSuccessful();
    expect(EmailTemplate::where('slug', 'orphaned-slug')->exists())->toBeTrue();

    // --prune removes them.
    $this->artisan('mail:sync-templates', ['--prune' => true])->assertSuccessful();
    expect(EmailTemplate::where('slug', 'orphaned-slug')->exists())->toBeFalse();
});
