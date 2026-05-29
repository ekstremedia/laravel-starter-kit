<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Notifications\Notifications\WorkspaceInvitationNotification;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\WorkspaceInvitation;
use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Email-based workspace invitations. A workspace admin invites an address
 * (which may or may not already have an account); the invitee accepts via a
 * tokenised link that threads them through registration/login and into the
 * workspace with the assigned role.
 *
 * store()/destroy() run inside a workspace (customer.admin gated); accept()
 * is a public route — the global tenant scope is inert there, so the
 * invitation resolves by its unique token regardless of context.
 */
class WorkspaceInvitationController extends Controller
{
    /** Admin invites someone to the active workspace. */
    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(CustomerMembership::assignableRoles())],
        ]);
        $email = mb_strtolower($data['email']);

        $invitee = User::query()->where('email', $email)->first();
        if ($invitee && $invitee->belongsToCustomer($workspace)) {
            return back()->with('error', __('invitations.flash.already_member', ['email' => $email]));
        }

        // One pending invite per email per workspace — replace any prior one.
        WorkspaceInvitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

        $invitation = WorkspaceInvitation::create([
            'tenant_id' => $workspace->id,
            'email' => $email,
            'role' => $data['role'],
            'token' => WorkspaceInvitation::freshToken(),
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($invitation));

        return back()->with('success', __('invitations.flash.sent', ['email' => $email]));
    }

    /** Admin revokes a pending invitation. */
    public function destroy(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        // Route-model binding runs through the global tenant scope, so the
        // invitation is guaranteed to belong to the active workspace.
        $invitation->delete();

        return back()->with('success', __('invitations.flash.revoked', ['email' => $invitation->email]));
    }

    /** Public: accept an invitation via its tokenised link. */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = WorkspaceInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return ($request->user() ? redirect()->route('app.landing') : redirect()->route('login'))
                ->with('error', __('invitations.flash.invalid'));
        }

        $user = $request->user();

        // Guest: remember the invite and send them to register (email
        // prefilled). After they register or log in, CustomerLandingController
        // finishes the acceptance.
        if (! $user) {
            $request->session()->put('workspace_invitation_token', $token);

            return redirect()->route('register', ['email' => $invitation->email]);
        }

        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            return redirect()->route('app.landing')
                ->with('error', __('invitations.flash.wrong_account', ['email' => $invitation->email]));
        }

        self::acceptFor($user, $invitation);

        return self::toWorkspace($invitation->tenant)
            ->with('success', __('invitations.flash.joined', ['workspace' => $invitation->tenant->name]));
    }

    /** Attach the user to the invited workspace with the invited role. */
    public static function acceptFor(User $user, WorkspaceInvitation $invitation): void
    {
        CustomerMembership::attach($user, $invitation->tenant, [$invitation->role]);
        $invitation->forceFill(['accepted_at' => now()])->save();
    }

    /** Redirect to the workspace dashboard (prefixed only in multi-tenant). */
    public static function toWorkspace(Tenant $workspace): RedirectResponse
    {
        return config('tenancy.enabled')
            ? redirect()->route('customer.dashboard', ['customer' => $workspace->slug])
            : redirect()->route('customer.dashboard');
    }

    private function workspace(Request $request): Tenant
    {
        $workspace = $request->attributes->get('customer');

        if (! $workspace instanceof Tenant) {
            throw new \LogicException('WorkspaceInvitationController requires an active workspace.');
        }

        return $workspace;
    }
}
