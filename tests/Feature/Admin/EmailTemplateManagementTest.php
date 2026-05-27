<?php

use App\Domains\Notifications\Mail\TemplateMail;
use App\Domains\Notifications\Models\EmailTemplate;
use App\Domains\Users\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(EmailTemplateSeeder::class);
});

it('shows templates on the mail settings page', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)
        ->get('/admin/mail')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('templates')
            ->has('settings')
        );
});

it('allows admin to update a template', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $template = EmailTemplate::forSlug('welcome', 'en');

    $this->actingAs($admin)
        ->patch("/admin/mail/templates/{$template->id}", [
            'subject' => 'Updated subject!',
            'heading' => 'New heading',
            'body' => 'Updated body text',
            'action_text' => 'Click here',
            'action_url' => 'https://example.com',
        ])
        ->assertRedirect();

    $template->refresh();
    expect($template->subject)->toBe('Updated subject!');
    expect($template->heading)->toBe('New heading');
    expect($template->body)->toBe('Updated body text');
    expect($template->compiled_html)->toContain('Updated body text');
});

it('returns preview HTML for a template', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $template = EmailTemplate::forSlug('welcome', 'en');

    $this->actingAs($admin)
        ->postJson("/admin/mail/templates/{$template->id}/preview")
        ->assertOk()
        ->assertJsonStructure(['html']);
});

it('sends a test email for a template', function () {
    Mail::fake();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $template = EmailTemplate::forSlug('admin-test', 'en');
    $email = fake()->safeEmail();

    $this->actingAs($admin)
        ->post("/admin/mail/templates/{$template->id}/test", [
            'email' => $email,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo($email));
});

it('rejects non-admin from updating templates', function () {
    $user = User::factory()->create();

    $template = EmailTemplate::forSlug('welcome', 'en');

    $this->actingAs($user)
        ->patch("/admin/mail/templates/{$template->id}", [
            'subject' => 'Hacked',
            'body' => 'Hacked body',
        ])
        ->assertForbidden();
});

it('validates required fields on template update', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $template = EmailTemplate::forSlug('welcome', 'en');

    $this->actingAs($admin)
        ->patch("/admin/mail/templates/{$template->id}", [
            'subject' => '',
            'body' => '',
        ])
        ->assertSessionHasErrors(['subject', 'body']);
});
