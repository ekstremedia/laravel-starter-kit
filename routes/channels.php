<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.health', function ($user) {
    return $user !== null && $user->isSuperAdmin();
});

Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    return $user->conversations()->whereKey((int) $conversationId)->exists();
});

// Per-customer live feed for company-shared files. Every member of the
// tenant gets auth'd; super admins are allowed through for support /
// debugging. Presence isn't exposed — clients only see "tree changed"
// pings carrying a version number, not the names of other connected users.
Broadcast::channel('customer.{tenantId}.files', function ($user, $tenantId) {
    if ($user === null) {
        return false;
    }
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return true;
    }

    return $user->customers()->where('workspaces.id', (int) $tenantId)->exists();
});
