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

// Per-workspace live feed for company-shared files. Every member of the
// tenant gets auth'd; super admins are allowed through for support /
// debugging. Presence isn't exposed — clients only see "tree changed"
// pings carrying a version number, not the names of other connected users.
Broadcast::channel('workspace.{workspaceId}.files', function ($user, $workspaceId) {
    if ($user === null) {
        return false;
    }
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return true;
    }

    return $user->workspaces()->where('workspaces.id', (int) $workspaceId)->exists();
});

// Central super-admin "a resource changed" feed (users, roles, permissions,
// workspaces, modules). Carries only resource+action+id pings; the client does
// an Inertia partial reload. Super-admins only — same gate as admin.health.
Broadcast::channel('admin.resources', function ($user) {
    return $user !== null && $user->isSuperAdmin();
});

// Per-workspace "a resource changed" feed (Equipment, categories, members, …).
// Same authorization as the files channel: members + super admins. Payload is
// just a change ping, so no row data leaks to other connected members.
Broadcast::channel('workspace.{workspaceId}.resources', function ($user, $workspaceId) {
    if ($user === null) {
        return false;
    }
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return true;
    }

    return $user->workspaces()->where('workspaces.id', (int) $workspaceId)->exists();
});
