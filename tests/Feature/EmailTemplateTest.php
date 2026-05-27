<?php

use App\Domains\Notifications\Models\EmailTemplate;
use App\Domains\Notifications\Notifications\AccountBannedNotification;
use App\Domains\Notifications\Notifications\AdminTestNotification;
use App\Domains\Notifications\Notifications\WelcomeNotification;
use App\Domains\Notifications\Services\MjmlCompiler;
use App\Domains\Users\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Notification;

it('compiles valid MJML to HTML', function () {
    // Instantiate the real compiler directly — the global test binding swaps
    // in a fake compiler to keep the rest of the suite fast.
    $compiler = new MjmlCompiler;

    $html = $compiler->compile('<mjml><mj-body><mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section></mj-body></mjml>');

    expect($html)->toContain('Hello');
    expect($html)->toContain('<!doctype html>');
});

it('seeds all 18 templates', function () {
    $this->seed(EmailTemplateSeeder::class);

    expect(EmailTemplate::count())->toBe(18);
    expect(EmailTemplate::whereNotNull('compiled_html')->count())->toBe(18);
});

it('finds template by slug and locale', function () {
    $this->seed(EmailTemplateSeeder::class);

    $template = EmailTemplate::forSlug('welcome', 'no');

    expect($template)->not->toBeNull();
    expect($template->locale)->toBe('no');
    expect($template->slug)->toBe('welcome');
});

it('falls back to English when locale template is missing', function () {
    $this->seed(EmailTemplateSeeder::class);

    EmailTemplate::query()->where('slug', 'welcome')->where('locale', 'no')->delete();

    $template = EmailTemplate::forSlug('welcome', 'no');

    expect($template)->not->toBeNull();
    expect($template->locale)->toBe('en');
});

it('interpolates variables in rendered HTML', function () {
    $this->seed(EmailTemplateSeeder::class);

    $template = EmailTemplate::forSlug('welcome', 'en');

    $html = $template->render(['user_name' => 'Alice', 'app_name' => 'TestApp', 'app_url' => 'https://test.com']);

    expect($html)->toContain('Alice');
});

it('interpolates subject variables', function () {
    $this->seed(EmailTemplateSeeder::class);

    $template = EmailTemplate::forSlug('welcome', 'en');

    $subject = $template->interpolateSubject(['app_name' => 'TestApp']);

    expect($subject)->toContain('TestApp');
});

it('sends welcome notification with template', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->notify(new WelcomeNotification);

    Notification::assertSentTo($user, WelcomeNotification::class);
});

it('sends account banned notification with template', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->notify(new AccountBannedNotification('Test reason'));

    Notification::assertSentTo($user, AccountBannedNotification::class);
});

it('sends admin test notification with template', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->notify(new AdminTestNotification('Hello test'));

    Notification::assertSentTo($user, AdminTestNotification::class);
});

it('recompiles when template is updated', function () {
    $this->seed(EmailTemplateSeeder::class);

    $template = EmailTemplate::forSlug('welcome', 'en');
    $originalHtml = $template->compiled_html;

    $template->update(['heading' => 'Updated heading!']);
    $template->compile();

    expect($template->compiled_html)->not->toBe($originalHtml);
    expect($template->compiled_html)->toContain('Updated heading!');
});
