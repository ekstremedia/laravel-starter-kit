<?php

use App\Domains\Access\Http\Controllers\PermissionController;
use App\Domains\Access\Http\Controllers\RoleController;
use App\Domains\Auth\Http\Controllers\DevLoginController;
use App\Domains\Auth\Http\Controllers\SocialiteController;
use App\Domains\Chat\Http\Controllers\ChatController;
use App\Domains\Files\Http\Controllers\PublicShareController;
use App\Domains\Modules\Http\Controllers\ModuleController;
use App\Domains\Notifications\Http\Controllers\EmailTemplateController;
use App\Domains\Notifications\Http\Controllers\MailLayoutController;
use App\Domains\Notifications\Http\Controllers\MailSettingsController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Notifications\Http\Controllers\NotificationPreferenceController;
use App\Domains\Operations\Http\Controllers\BackupController;
use App\Domains\Operations\Http\Controllers\HealthController;
use App\Domains\Operations\Http\Controllers\HomeController;
use App\Domains\Operations\Http\Controllers\ImpersonateController;
use App\Domains\Operations\Http\Controllers\MonitoringController;
use App\Domains\Operations\Http\Controllers\OverviewController;
use App\Domains\Operations\Http\Controllers\StorageDashboardController;
use App\Domains\Operations\Http\Controllers\SystemInfoController;
use App\Domains\Settings\Http\Controllers\AppSettingsController;
use App\Domains\Settings\Http\Controllers\SettingsController;
use App\Domains\Users\Http\Controllers\AvatarController;
use App\Domains\Users\Http\Controllers\PersonalAccessTokenController;
use App\Domains\Users\Http\Controllers\UserController;
use App\Domains\Users\Http\Controllers\UserProfileController;
use App\Domains\Workspaces\Http\Controllers\WorkspaceController;
use App\Domains\Workspaces\Http\Controllers\WorkspaceInvitationController;
use App\Domains\Workspaces\Http\Controllers\WorkspaceLandingController;
use App\Domains\Workspaces\Http\Controllers\WorkspaceOnboardingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Public legal pages — shipped as styled placeholders the marketing footer
// links resolve to out of the box. Replace the copy in resources/js/Pages/Legal.vue.
Route::get('/privacy', fn () => Inertia::render('Legal', ['kind' => 'privacy']))->name('legal.privacy');
Route::get('/terms', fn () => Inertia::render('Legal', ['kind' => 'terms']))->name('legal.terms');

// Public, unauthenticated share links. Full shares carry optional password
// gating; signed links are Laravel-signed URLs with no DB row.
Route::get('/share/{token}', [PublicShareController::class, 'view'])->name('public.share.view');
Route::post('/share/{token}/unlock', [PublicShareController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('public.share.unlock');
Route::get('/share/{token}/files/{fileId}/download', [PublicShareController::class, 'download'])
    ->whereNumber('fileId')
    ->name('public.share.download');
Route::get('/share/signed/file/{file}', [PublicShareController::class, 'signedDownload'])
    ->whereNumber('file')
    ->middleware('signed')
    ->name('public.share.signed');

// Accept a workspace invitation. Public so a brand-new invitee reaches it
// before they have an account — the controller threads guests through
// registration/login and finishes the join via WorkspaceLandingController.
Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'accept'])
    ->name('workspace.invitations.accept');

// Dev easy-login (local/test only)
if (app()->isLocal() || app()->runningUnitTests()) {
    Route::middleware('guest')->group(function () {
        Route::post('/login/dev', [DevLoginController::class, 'store'])->name('login.dev');
    });
}

// Socialite OAuth entry points. The controller itself aborts 404 when the
// provider isn't enabled, so we register the routes unconditionally — that
// way OAuth callbacks keep resolving even when the feature is toggled at
// runtime without a route-cache rebuild.
Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->whereIn('provider', ['google', 'github'])
    ->name('oauth.redirect');
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->whereIn('provider', ['google', 'github'])
    ->name('oauth.callback');

// Authenticated routes (user-level, workspace-agnostic)
Route::middleware('auth')->group(function () {
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // Central routes — accessible without a workspace context (e.g. from the
    // picker page or admin panel). The workspace-scoped copies in workspace.php
    // take precedence when a workspace is active.
    Route::middleware('verified')->group(function () {
        // Public-ish profile for any user, keyed by UUID so URLs aren't
        // enumerable. Visibility is gated by UserProfilePolicy@view inside
        // the controller.
        Route::get('/u/{user:public_id}', [UserProfileController::class, 'show'])->name('users.profile.show');

        Route::get('/profile', fn () => Inertia::render('Profile'))->name('profile.central');
        // Avatar endpoints are also registered centrally — admins visiting
        // /profile without an active workspace (e.g. from the picker page or
        // the admin panel) would otherwise hit a 404 on upload, since the
        // workspace.php copy only exists under /w/{workspace}/...
        Route::post('/profile/avatar', [AvatarController::class, 'store'])->name('profile.avatar.central.store');
        Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.central.destroy');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.central.index');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.central.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.central.readAll');
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.central.destroy');
        Route::delete('/notifications', [NotificationController::class, 'destroyAll'])->name('notifications.central.destroyAll');

        // Notification preferences
        Route::get('/settings/notifications', [NotificationPreferenceController::class, 'index'])->name('settings.notifications');
        Route::put('/settings/notifications', [NotificationPreferenceController::class, 'update'])->name('settings.notifications.update');

        // Personal API tokens (Sanctum). User-owned; self-service create + revoke.
        Route::get('/settings/tokens', [PersonalAccessTokenController::class, 'index'])->name('settings.tokens.index');
        Route::post('/settings/tokens', [PersonalAccessTokenController::class, 'store'])->name('settings.tokens.store');
        Route::delete('/settings/tokens/{id}', [PersonalAccessTokenController::class, 'destroy'])->whereNumber('id')->name('settings.tokens.destroy');

        // Chat (only when CHAT_ENABLED=true)
        Route::middleware('chat.enabled')->group(function () {
            Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
            Route::get('/chat/conversations-list', [ChatController::class, 'conversationsJson'])->name('chat.conversations.list');
            Route::post('/chat/conversations', [ChatController::class, 'store'])->name('chat.conversations.store');
            Route::get('/chat/conversations/{conversation}', [ChatController::class, 'show'])->name('chat.conversations.show');
            Route::post('/chat/conversations/{conversation}/messages', [ChatController::class, 'sendMessage'])
                ->name('chat.conversations.messages.store');
            Route::get('/chat/conversations/{conversation}/attachments/{media}', [ChatController::class, 'downloadAttachment'])->name('chat.conversations.attachments.download');
            Route::post('/chat/conversations/{conversation}/read', [ChatController::class, 'markRead'])->name('chat.conversations.read');
            Route::post('/chat/read-all', [ChatController::class, 'markAllRead'])->name('chat.read-all');
            Route::get('/chat/users/search', [ChatController::class, 'searchUsers'])->name('chat.users.search');
        });
    });
});

// Post-login landing — redirects to the user's workspace or renders the picker.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/app', WorkspaceLandingController::class)->name('app.landing');

    // Command-design "Min side" — user overview inside the CommandLayout shell.
    Route::get('/home', [HomeController::class, 'index'])->name('home.me');

    // Self-serve workspace onboarding (multi-tenant create_own mode). Central
    // route — a brand-new user has no membership yet, so this can't live under
    // the /w/{workspace} group (ResolveWorkspace would reject them).
    Route::get('/onboarding/workspace', [WorkspaceOnboardingController::class, 'show'])->name('workspaces.onboarding.show');
    Route::post('/onboarding/workspace', [WorkspaceOnboardingController::class, 'store'])->name('workspaces.onboarding.store');
});

// Admin routes (system super-user — spans all tenants)
Route::middleware(['auth', 'verified', 'super.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [OverviewController::class, 'index'])->name('overview');
        Route::get('overview/metrics', [OverviewController::class, 'metrics'])->name('overview.metrics');

        Route::resource('users', UserController::class);
        Route::post('users/bulk-email', [UserController::class, 'bulkEmail'])->name('users.bulkEmail');
        Route::post('users/{user}/verify', [UserController::class, 'verify'])->name('users.verify');
        Route::post('users/{user}/unverify', [UserController::class, 'unverify'])->name('users.unverify');
        Route::post('users/{user}/ban', [UserController::class, 'ban'])->name('users.ban');
        Route::post('users/{user}/unban', [UserController::class, 'unban'])->name('users.unban');
        Route::post('users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resendVerification');
        Route::post('users/{user}/reset-2fa', [UserController::class, 'reset2fa'])->name('users.reset2fa');
        Route::post('users/{user}/send-password-reset', [UserController::class, 'sendPasswordReset'])->name('users.sendPasswordReset');
        Route::post('users/{user}/notify-test', [UserController::class, 'notifyTest'])->name('users.notifyTest');
        Route::patch('users/{user}/quota', [UserController::class, 'setQuota'])->name('users.setQuota');
        Route::patch('users/{user}/role', [UserController::class, 'setRole'])->name('users.setRole');
        Route::patch('users/{user}/platform-permission', [UserController::class, 'setPlatformPermission'])->name('users.platformPermission');
        // Single-row JSON for surgical live updates (see docs/realtime-and-broadcasting.md).
        Route::get('users/{user}/live-row', [UserController::class, 'liveRow'])->name('users.live-row');

        Route::resource('roles', RoleController::class)->except(['show']);
        Route::get('roles/{role}/live-row', [RoleController::class, 'liveRow'])->name('roles.live-row');

        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions', [PermissionController::class, 'store'])->name('permissions.store');
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');
        Route::get('permissions/{permission}/live-row', [PermissionController::class, 'liveRow'])->name('permissions.live-row');

        Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::get('activity', [MonitoringController::class, 'activityRedirect'])->name('activity.index');

        Route::post('health/queue', [HealthController::class, 'dispatchPing'])->name('health.queue');
        Route::post('health/broadcast', [HealthController::class, 'broadcastPing'])->name('health.broadcast');
        Route::get('health/queue-last', [HealthController::class, 'queueLast'])->name('health.queue.last');

        // SMTP transport + the global email layout (branding) are super-admin
        // only. The per-email content editor (GET mail page + template routes)
        // lives in a separate, delegatable group below.
        Route::patch('mail', [MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('mail/test', [MailSettingsController::class, 'test'])->name('mail.test');
        Route::patch('mail/layout', [MailLayoutController::class, 'update'])->name('mail.layout.update');
        Route::post('mail/layout/preview', [MailLayoutController::class, 'preview'])->name('mail.layout.preview');

        Route::get('system', [SystemInfoController::class, 'show'])->name('system.show');
        Route::get('health', fn () => redirect()->route('admin.system.show'))->name('health.show');

        Route::get('settings', [AppSettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [AppSettingsController::class, 'update'])->name('settings.update');

        // Module registry — enable/disable domain modules, see per-module stats,
        // and purge a module's data.
        Route::get('modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::patch('modules/{module}', [ModuleController::class, 'update'])->name('modules.update');
        Route::post('modules/{module}/purge', [ModuleController::class, 'purge'])->name('modules.purge');
        Route::get('modules/{module}/live-row', [ModuleController::class, 'liveRow'])->name('modules.live-row');

        Route::get('storage', [StorageDashboardController::class, 'index'])->name('storage.index');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups/run', [BackupController::class, 'run'])->name('backups.run');
        Route::post('backups/clean', [BackupController::class, 'clean'])->name('backups.clean');
        Route::get('backups/download', [BackupController::class, 'download'])->name('backups.download');
        Route::post('backups/prepare-restore', [BackupController::class, 'prepareRestore'])->name('backups.prepareRestore');

        // Platform admin — workspace management.
        Route::resource('workspaces', WorkspaceController::class)->except(['show']);
        Route::get('workspaces/{workspace}/live-row', [WorkspaceController::class, 'liveRow'])->name('workspaces.live-row');
        Route::post('workspaces/{workspace}/members', [WorkspaceController::class, 'attachMember'])->name('workspaces.members.attach');
        Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'detachMember'])->name('workspaces.members.detach');

        Route::post('users/{user}/workspaces', [UserController::class, 'attachWorkspace'])->name('users.workspaces.attach');
        Route::patch('users/{user}/workspaces/{workspace}/role', [UserController::class, 'setWorkspaceRole'])->name('users.workspaces.setRole');
        Route::delete('users/{user}/workspaces/{workspace}', [UserController::class, 'detachWorkspace'])->name('users.workspaces.detach');

        Route::post('users/{user}/impersonate', [ImpersonateController::class, 'take'])->name('users.impersonate');
    });

// Email content editor — delegatable beyond super-admins via the
// `manage email templates` capability (super-admins pass through Gate::before).
// The page + template routes live here; SMTP + layout stay super-admin-only above.
Route::middleware(['auth', 'verified', 'can:manage email templates'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('mail', [MailSettingsController::class, 'show'])->name('mail.show');
        Route::patch('mail/templates/{template}', [EmailTemplateController::class, 'update'])->name('mail.templates.update');
        Route::post('mail/templates/{template}/preview', [EmailTemplateController::class, 'preview'])->name('mail.templates.preview');
        Route::post('mail/templates/{template}/test', [EmailTemplateController::class, 'testSend'])->name('mail.templates.test');
    });

// Impersonation — leave action must be available from within the impersonated session
Route::middleware('auth')->group(function () {
    Route::post('/impersonate/leave', [ImpersonateController::class, 'leave'])->name('impersonate.leave');
});

// Catch-all for unmatched URLs (always matched last). Living inside the `web`
// group means even a 404 runs through the session + Inertia shared props, so
// the error page renders the full app chrome (rail + topbar) for a logged-in
// user instead of the bare guest shell.
Route::fallback(fn () => abort(404));
