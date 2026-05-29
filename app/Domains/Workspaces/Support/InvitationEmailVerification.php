<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Support;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Session\Session;

/**
 * Marks a just-authenticated user's email as verified when they arrived via a
 * valid, matching workspace invitation — so invitees aren't asked to re-prove
 * an inbox they already proved.
 *
 * Why this is safe. The invite link carries a secret token (Str::random(48),
 * see WorkspaceInvitation::freshToken()) that is delivered ONLY to the invited
 * address. Clicking it therefore proves control of that inbox exactly as a
 * verification link does — they're the same mechanism, with the same forwarding
 * caveat. This mirrors how SocialiteController auto-verifies only emails of
 * trusted provenance.
 *
 * Strictly gated, so guessing an email can't bypass verification:
 *   1. A pending invitation token must already be in the session. It is written
 *      in exactly one place — WorkspaceInvitationController::accept() — and only
 *      after resolving the secret token. Typing an invited address into the
 *      register form puts no token in the session, so it does nothing.
 *   2. The invitation's email must match the authenticated user's email. If they
 *      register/sign in as a different address, we never auto-verify it (and the
 *      landing controller won't accept the invitation either).
 *
 * The token is peeked, not consumed — WorkspaceLandingController still pulls it
 * to finish attaching workspace membership once the user reaches `/app`.
 */
class InvitationEmailVerification
{
    /** @return bool whether the email was just auto-verified */
    public static function attempt(User $user, Session $session): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $token = $session->get('workspace_invitation_token');
        if (! is_string($token) || $token === '') {
            return false;
        }

        $invitation = WorkspaceInvitation::query()->where('token', $token)->first();

        if (! $invitation
            || ! $invitation->isPending()
            || mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            return false;
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return true;
    }
}
