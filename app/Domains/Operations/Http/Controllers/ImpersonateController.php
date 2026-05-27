<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

class ImpersonateController extends Controller
{
    public function take(User $user, ImpersonateManager $manager): Response
    {
        $me = auth()->user();

        if (! $me->canImpersonate() || ! $user->canBeImpersonated()) {
            abort(403);
        }

        // Impersonation activity is intentionally NOT logged to activity_log;
        // the banner at the top of every page already signals the session is
        // impersonated, and the noise drowned out useful admin signal.

        $manager->take($me, $user);

        // The session identity changes mid-request. A plain Laravel redirect
        // would be followed by Inertia's XHR as a GET to /app — but the SPA
        // shell is still rendering on /admin/users/{id}/impersonate (a
        // super.admin-guarded URL), so it 403s before the new page swaps in.
        // `Inertia::location` forces a hard reload so the browser reconnects
        // with the new session at the target URL.
        return Inertia::location(route('app.landing'));
    }

    public function leave(ImpersonateManager $manager): Response
    {
        if ($manager->isImpersonating()) {
            $manager->leave();
        }

        // Same reasoning as `take`: force a full reload so the Inertia shell
        // re-hydrates against the restored (super admin) session.
        return Inertia::location(route('admin.users.index'));
    }
}
