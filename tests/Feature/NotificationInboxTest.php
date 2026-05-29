<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->workspace = createWorkspace();
    $this->user = User::factory()->create();
    joinWorkspace($this->user, $this->workspace);
});

function createDbNotification(User $user, array $data = ['title' => 'Hi']): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'TestNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => $data,
        'read_at' => null,
    ]);
}

it('requires auth to read notifications', function () {
    $this->getJson(workspaceUrl($this->workspace, '/notifications'))->assertUnauthorized();
});

it('returns the unread count and recent notifications', function () {
    createDbNotification($this->user);
    createDbNotification($this->user, ['title' => 'Second']);

    $this->actingAs($this->user)
        ->getJson(workspaceUrl($this->workspace, '/notifications'))
        ->assertOk()
        ->assertJson(['unread_count' => 2]);
});

it('marks a single notification as read', function () {
    $n = createDbNotification($this->user);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, "/notifications/{$n->id}/read"))
        ->assertRedirect();

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('marks all notifications as read', function () {
    createDbNotification($this->user);
    createDbNotification($this->user);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/notifications/read-all'))
        ->assertRedirect();

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('deletes a notification', function () {
    $n = createDbNotification($this->user);

    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, "/notifications/{$n->id}"))
        ->assertRedirect();

    expect(DatabaseNotification::find($n->id))->toBeNull();
});

it('shares the unread count via Inertia', function () {
    createDbNotification($this->user);
    createDbNotification($this->user);

    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.unread_notifications_count', 2));
});
