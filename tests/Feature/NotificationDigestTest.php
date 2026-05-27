<?php

use App\Domains\Notifications\Notifications\NotificationDigestNotification;
use App\Domains\Notifications\Notifications\WelcomeNotification;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('queues a digest notification for users who opted in', function () {
    // Seed real database notifications first — fake() only after, or the
    // command's unreadNotifications() query returns nothing.
    $optedIn = User::factory()->create();
    $optedIn->settings()->merge(['notification_digest' => 'daily']);
    $optedIn->notify(new WelcomeNotification);

    $optedOut = User::factory()->create();
    $optedOut->notify(new WelcomeNotification);

    Notification::fake();

    Artisan::call('notifications:digest', ['--frequency' => 'daily']);

    Notification::assertSentTo($optedIn, NotificationDigestNotification::class);
    Notification::assertNotSentTo($optedOut, NotificationDigestNotification::class);
});

it('rejects an invalid frequency', function () {
    $exitCode = Artisan::call('notifications:digest', ['--frequency' => 'hourly']);

    expect($exitCode)->toBe(1);
});

it('skips users who have no unread notifications in the window', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->settings()->merge(['notification_digest' => 'daily']);
    // Intentionally no unread notifications created.

    Artisan::call('notifications:digest', ['--frequency' => 'daily']);

    Notification::assertNotSentTo($user, NotificationDigestNotification::class);
});
