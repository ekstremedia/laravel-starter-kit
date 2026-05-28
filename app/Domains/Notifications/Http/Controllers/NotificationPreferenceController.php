<?php

namespace App\Domains\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = $request->user()->settings()->resolved();

        return Inertia::render('Settings/Notifications', [
            'preferences' => [
                'notification_email_immediate' => $settings['notification_email_immediate'] ?? false,
                'notification_digest' => $settings['notification_digest'] ?? 'none',
                'notification_chat_messages' => $settings['notification_chat_messages'] ?? true,
                'notification_account_updates' => $settings['notification_account_updates'] ?? true,
                'notification_system_alerts' => $settings['notification_system_alerts'] ?? true,
                'notification_storage_alerts' => $settings['notification_storage_alerts'] ?? true,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notification_email_immediate' => 'required|boolean',
            'notification_digest' => 'required|in:none,daily,weekly',
            'notification_chat_messages' => 'required|boolean',
            'notification_account_updates' => 'required|boolean',
            'notification_system_alerts' => 'required|boolean',
            'notification_storage_alerts' => 'required|boolean',
        ]);

        $request->user()->settings()->merge($validated);

        return back()->with('success', __('flash.notification_prefs.saved'));
    }
}
