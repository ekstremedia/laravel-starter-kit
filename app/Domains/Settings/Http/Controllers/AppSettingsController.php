<?php

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Files\Support\UploadLimits;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/AppSettings', [
            'settings' => AppSetting::current()->only([
                'site_up', 'registration_open', 'login_enabled', 'require_email_verification',
                'default_role', 'require_2fa_for_admins', 'send_welcome_notification',
                'maintenance_message', 'announcement_banner', 'announcement_severity',
                'files_feature_enabled', 'max_share_days',
                'default_personal_storage_bytes', 'default_entity_storage_bytes',
                'max_upload_bytes', 'chat_max_upload_bytes',
            ]),
            // The hard ceiling the running PHP process accepts. Surfaced so the
            // settings UI can show the admin what they can't exceed (and why a
            // larger value is rejected).
            'php_upload_ceiling_bytes' => UploadLimits::phpCeilingBytes(),
            // Only workspace-scoped roles are valid as a Fortify default — the
            // registration flow hands the new user off to `WorkspaceMembership`,
            // which would reject SuperAdmin (a platform flag, not a role).
            'roles' => WorkspaceMembership::assignableRoles(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_up' => ['required', 'boolean'],
            'registration_open' => ['required', 'boolean'],
            'login_enabled' => ['required', 'boolean'],
            'require_email_verification' => ['required', 'boolean'],
            'default_role' => ['required', 'string', Rule::in(WorkspaceMembership::assignableRoles())],
            'require_2fa_for_admins' => ['required', 'boolean'],
            'send_welcome_notification' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'announcement_banner' => ['nullable', 'string', 'max:500'],
            'announcement_severity' => ['required', 'in:info,warn,danger,success'],
            'files_feature_enabled' => ['required', 'boolean'],
            'max_share_days' => ['required', 'integer', 'min:1', 'max:30'],
            // Global fallback for personal storage quota. Null = unlimited
            // (no cap), -1 = explicit unlimited, 0 = blocked, N>0 = byte cap.
            // Capped at JS safe-integer range so Inertia round-trips keep
            // precision.
            'default_personal_storage_bytes' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:'.((2 ** 53) - 1)],
            // Global fallback cap for file-owning entities (Assets, …). Same
            // sentinel convention as the personal default above.
            'default_entity_storage_bytes' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:'.((2 ** 53) - 1)],
            // Per-file upload ceiling. Clamped server-side to the running PHP
            // process limit — the admin can't promise more than the server
            // accepts. Floor of 1 KB so it can never be set to "nothing".
            // `sometimes` so partial settings updates that omit it still pass.
            'max_upload_bytes' => ['sometimes', 'integer', 'min:1024', 'max:'.UploadLimits::phpCeilingBytes()],
            // Per-file ceiling for chat attachments — same clamping as above.
            'chat_max_upload_bytes' => ['sometimes', 'integer', 'min:1024', 'max:'.UploadLimits::phpCeilingBytes()],
        ]);

        $settings = AppSetting::current();
        $changes = collect($data)
            ->filter(fn ($v, $k) => $v != $settings->$k)
            ->keys()
            ->values()
            ->all();

        $settings->fill($data)->save();

        activity('app_settings')
            ->performedOn($settings)
            ->withProperties(['changed' => $changes])
            ->event('updated')
            ->log('Updated app settings');

        return back()->with('success', __('flash.app_settings.saved'));
    }
}
