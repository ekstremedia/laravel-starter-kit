<?php

declare(strict_types=1);

use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->workspace = createWorkspace();
    $this->workspace->update(['files_feature_enabled' => true]);

    $this->user = User::factory()->create();
    joinWorkspace($this->user, $this->workspace);
    $this->user->settings()->merge(['files_enabled' => true]);
});

it('returns 404 when the global app setting is off', function () {
    AppSetting::current()->update(['files_feature_enabled' => false]);

    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/files'))
        ->assertNotFound();
});

it('returns OK when all three flags are on', function () {
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/files'))
        ->assertOk();
});

it('admin can toggle the feature flag from /admin/settings', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $current = AppSetting::current();

    $this->actingAs($admin)
        ->patch('/admin/settings', [
            'site_up' => $current->site_up,
            'registration_open' => $current->registration_open,
            'login_enabled' => $current->login_enabled,
            'require_email_verification' => $current->require_email_verification,
            'default_role' => $current->default_role ?? 'User',
            'require_2fa_for_admins' => $current->require_2fa_for_admins,
            'send_welcome_notification' => $current->send_welcome_notification,
            'maintenance_message' => null,
            'announcement_banner' => null,
            'announcement_severity' => 'info',
            'files_feature_enabled' => true,
            'max_share_days' => 14,
        ])
        ->assertRedirect();

    expect(AppSetting::current()->files_feature_enabled)->toBeTrue()
        ->and(AppSetting::current()->max_share_days)->toBe(14);
});
