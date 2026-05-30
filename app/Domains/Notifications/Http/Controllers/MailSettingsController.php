<?php

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Mail\TestMail;
use App\Domains\Notifications\Models\EmailTemplate;
use App\Domains\Notifications\Models\MailLayout;
use App\Domains\Notifications\Models\MailSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        $templates = EmailTemplate::query()
            ->orderBy('slug')
            ->orderBy('locale')
            ->get()
            ->groupBy('slug')
            ->map(fn ($group) => [
                'name' => $group->first()->name,
                'slug' => $group->first()->slug,
                'variables' => $group->first()->variables,
                'locales' => $group->map(fn (EmailTemplate $t) => [
                    'id' => $t->id,
                    'locale' => $t->locale,
                    'subject' => $t->subject,
                    'heading' => $t->heading,
                    'body' => $t->body,
                    'action_text' => $t->action_text,
                    'action_url' => $t->action_url,
                    'has_compiled' => ! empty($t->compiled_html),
                ])->values()->all(),
            ])->values()->all();

        // SMTP transport + the global layout/branding are super-admin only —
        // never leak them to delegated "manage email templates" users.
        $settings = null;
        $layout = null;

        if ($isSuperAdmin) {
            $s = MailSetting::current();
            $settings = [
                'mailer' => $s->mailer,
                'host' => $s->host,
                'port' => $s->port,
                'encryption' => $s->encryption,
                'username' => $s->username,
                'has_password' => ! empty($s->password),
                'from_address' => $s->from_address,
                'from_name' => $s->from_name,
                'enabled' => $s->enabled,
            ];

            $l = MailLayout::current();
            $layout = $l->only($l->getFillable());
        }

        return Inertia::render('Admin/Mail', [
            'settings' => $settings,
            'layout' => $layout,
            'templates' => $templates,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mailer' => ['required', 'string', 'max:50'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);

        $settings = MailSetting::current();

        // Empty password field = "leave existing unchanged".
        $passwordChanged = ! empty($data['password']);
        if (! $passwordChanged) {
            unset($data['password']);
        }

        $settings->fill($data)->save();

        activity('mail_settings')
            ->performedOn($settings)
            ->withProperties(['password_changed' => $passwordChanged])
            ->event('updated')
            ->log('Updated mail settings');

        return back();
    }

    public function test(Request $request): RedirectResponse
    {
        MailSetting::current()->applyToConfig();

        try {
            Mail::to($request->user()->email)->queue(new TestMail('Sent from Admin → Mail Settings'));
        } catch (Throwable $e) {
            Log::error('Admin mail test failed', ['exception' => $e]);

            return back()->with('error', 'Mail test failed. Check server logs.');
        }

        return back()->with('success', __('flash.mail_settings.test_queued', ['email' => $request->user()->email]));
    }
}
