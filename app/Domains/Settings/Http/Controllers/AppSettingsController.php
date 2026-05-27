<?php

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Support\CustomerMembership;
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
                'default_personal_storage_bytes',
            ]),
            // Only customer-scoped roles are valid as a Fortify default — the
            // registration flow hands the new user off to `CustomerMembership`,
            // which would reject SuperAdmin (a platform flag, not a role).
            'roles' => CustomerMembership::assignableRoles(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_up' => ['required', 'boolean'],
            'registration_open' => ['required', 'boolean'],
            'login_enabled' => ['required', 'boolean'],
            'require_email_verification' => ['required', 'boolean'],
            'default_role' => ['required', 'string', Rule::in(CustomerMembership::assignableRoles())],
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
