<?php

use App\Domains\Notifications\Jobs\RecompileEmailTemplatesJob;
use App\Domains\Notifications\Models\MailLayout;
use App\Domains\Users\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(EmailTemplateSeeder::class);
});

it('includes layout data on the mail page for super admins', function () {
    $this->actingAs(makeSuperAdmin(User::factory()->create()))
        ->get('/admin/mail')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('layout.brand_color')
            ->has('layout.footer_text')
            ->where('layout.header_mode', 'text')
        );
});

it('saves the layout and dispatches a recompile job', function () {
    Bus::fake();

    $this->actingAs(makeSuperAdmin(User::factory()->create()))
        ->patch('/admin/mail/layout', [
            'brand_color' => '#123456',
            'button_color' => '#abcdef',
            'body_bg' => '#fff',
            'card_bg' => '#ffffff',
            'text_color' => '#000000',
            'heading_color' => '#111111',
            'footer_color' => '#999999',
            'font_family' => 'Georgia, serif',
            'header_mode' => 'text',
            'header_logo_url' => null,
            'footer_text' => '© {{ year }} {{ app_name }}.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $layout = MailLayout::current();
    expect($layout->brand_color)->toBe('#123456');
    expect($layout->font_family)->toBe('Georgia, serif');

    Bus::assertDispatched(RecompileEmailTemplatesJob::class);
});

it('validates layout colour and url fields', function () {
    $this->actingAs(makeSuperAdmin(User::factory()->create()))
        ->patch('/admin/mail/layout', [
            'brand_color' => 'not-a-hex',
            'button_color' => '#4f46e5',
            'body_bg' => '#f3f4f6',
            'card_bg' => '#ffffff',
            'text_color' => '#374151',
            'heading_color' => '#111827',
            'footer_color' => '#9ca3af',
            'font_family' => 'Arial',
            'header_mode' => 'banana',
            'header_logo_url' => 'ftp://evil.test/logo.png',
            'footer_text' => '',
        ])
        ->assertSessionHasErrors(['brand_color', 'header_mode', 'header_logo_url', 'footer_text']);
});

it('returns live preview HTML reflecting draft layout values', function () {
    $this->actingAs(makeSuperAdmin(User::factory()->create()))
        ->postJson('/admin/mail/layout/preview', [
            'brand_color' => '#e11d48',
            'button_color' => '#4f46e5',
            'body_bg' => '#f3f4f6',
            'card_bg' => '#ffffff',
            'text_color' => '#374151',
            'heading_color' => '#111827',
            'footer_color' => '#9ca3af',
            'font_family' => 'Arial, Helvetica, sans-serif',
            'header_mode' => 'text',
            'header_logo_url' => null,
            'footer_text' => 'PREVIEW-ONLY footer',
        ])
        ->assertOk()
        ->assertJsonStructure(['html']);

    // The draft must not be persisted by a preview.
    expect(MailLayout::current()->footer_text)->not->toContain('PREVIEW-ONLY');
});

it('forbids non-super-admins from editing the layout', function () {
    $user = User::factory()->create();
    $user->forceFill(['platform_permissions' => ['manage_email_templates']])->save();

    $this->actingAs($user)
        ->patch('/admin/mail/layout', ['brand_color' => '#000000'])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/admin/mail/layout/preview', ['brand_color' => '#000000'])
        ->assertForbidden();
});
