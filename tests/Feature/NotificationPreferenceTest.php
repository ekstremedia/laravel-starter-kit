<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config()->set('chat.connection', config('database.default'));

    $this->customer = createCustomer();
    $this->user = User::factory()->create();
    joinCustomer($this->user, $this->customer);
});

it('renders the notification preferences page', function () {
    $this->actingAs($this->user)
        ->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Notifications')
            ->has('preferences')
            ->where('preferences.notification_email_immediate', false)
            ->where('preferences.notification_digest', 'none')
            ->where('preferences.notification_chat_messages', true)
            ->where('preferences.notification_account_updates', true)
            ->where('preferences.notification_system_alerts', true)
        );
});

it('requires auth to view preferences', function () {
    $this->get('/settings/notifications')
        ->assertRedirect();
});

it('saves notification preferences', function () {
    $this->actingAs($this->user)
        ->put('/settings/notifications', [
            'notification_email_immediate' => true,
            'notification_digest' => 'daily',
            'notification_chat_messages' => false,
            'notification_account_updates' => true,
            'notification_system_alerts' => false,
            'notification_storage_alerts' => true,
        ])
        ->assertRedirect();

    $settings = $this->user->settings()->resolved();

    expect($settings['notification_email_immediate'])->toBeTrue()
        ->and($settings['notification_digest'])->toBe('daily')
        ->and($settings['notification_chat_messages'])->toBeFalse()
        ->and($settings['notification_account_updates'])->toBeTrue()
        ->and($settings['notification_system_alerts'])->toBeFalse()
        ->and($settings['notification_storage_alerts'])->toBeTrue();
});

it('validates digest frequency value', function () {
    $this->actingAs($this->user)
        ->putJson('/settings/notifications', [
            'notification_email_immediate' => true,
            'notification_digest' => 'hourly', // invalid
            'notification_chat_messages' => true,
            'notification_account_updates' => true,
            'notification_system_alerts' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notification_digest');
});

it('requires all preference fields', function () {
    $this->actingAs($this->user)
        ->putJson('/settings/notifications', [
            'notification_email_immediate' => true,
            // missing other fields
        ])
        ->assertUnprocessable();
});
