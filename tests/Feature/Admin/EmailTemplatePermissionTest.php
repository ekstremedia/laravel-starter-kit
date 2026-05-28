<?php

use App\Domains\Notifications\Models\EmailTemplate;
use App\Domains\Users\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(EmailTemplateSeeder::class);
});

/** A verified non-super-admin holding the delegatable capability. */
function delegatedEditor(): User
{
    $user = User::factory()->create();
    $user->forceFill(['platform_permissions' => ['manage_email_templates']])->save();

    return $user->refresh();
}

it('grants the gate via the platform_permissions column', function () {
    expect(delegatedEditor()->can('manage email templates'))->toBeTrue();

    $plain = User::factory()->create();
    expect($plain->can('manage email templates'))->toBeFalse();
});

it('lets a delegated editor open the mail page with templates but no smtp/layout', function () {
    $this->actingAs(delegatedEditor())
        ->get('/admin/mail')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Mail')
            ->has('templates')
            ->where('settings', null)
            ->where('layout', null)
        );
});

it('lets a delegated editor edit and preview template content', function () {
    $editor = delegatedEditor();
    $template = EmailTemplate::forSlug('welcome', 'en');

    $this->actingAs($editor)
        ->patch("/admin/mail/templates/{$template->id}", [
            'subject' => 'Delegated subject',
            'heading' => 'Hi',
            'body' => 'Delegated body',
            'action_text' => '',
            'action_url' => '',
        ])
        ->assertRedirect();

    expect($template->refresh()->subject)->toBe('Delegated subject');

    $this->actingAs($editor)
        ->postJson("/admin/mail/templates/{$template->id}/preview")
        ->assertOk()
        ->assertJsonStructure(['html']);
});

it('forbids the mail page for users without the capability', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/mail')
        ->assertForbidden();
});

it('shares the capability flag through Inertia for delegated editors', function () {
    $this->actingAs(delegatedEditor())
        ->get('/home')
        ->assertInertia(fn ($page) => $page
            ->where('auth.can.manage_email_templates', true)
        );

    $this->actingAs(User::factory()->create())
        ->get('/home')
        ->assertInertia(fn ($page) => $page
            ->where('auth.can.manage_email_templates', false)
        );
});

it('lets a super admin grant and revoke the capability', function () {
    $admin = makeSuperAdmin(User::factory()->create());
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/platform-permission", [
            'capability' => 'manage_email_templates',
            'enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($target->refresh()->hasPlatformPermission('manage_email_templates'))->toBeTrue();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/platform-permission", [
            'capability' => 'manage_email_templates',
            'enabled' => false,
        ])
        ->assertRedirect();

    $target->refresh();
    expect($target->hasPlatformPermission('manage_email_templates'))->toBeFalse();
    expect($target->platform_permissions)->toBeNull();
});

it('rejects unknown capabilities', function () {
    $admin = makeSuperAdmin(User::factory()->create());
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/platform-permission", [
            'capability' => 'delete_everything',
            'enabled' => true,
        ])
        ->assertSessionHasErrors('capability');
});

it('forbids non-super-admins from granting platform permissions', function () {
    $target = User::factory()->create();

    $this->actingAs(delegatedEditor())
        ->patch("/admin/users/{$target->id}/platform-permission", [
            'capability' => 'manage_email_templates',
            'enabled' => true,
        ])
        ->assertForbidden();

    expect($target->refresh()->hasPlatformPermission('manage_email_templates'))->toBeFalse();
});
