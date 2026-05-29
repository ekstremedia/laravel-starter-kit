<?php

namespace App\Domains\Auth\Http\Responses;

use App\Domains\Workspaces\Support\InvitationEmailVerification;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        // An existing, still-unverified account that signs in carrying a valid
        // matching invitation has proved inbox control via the invite link —
        // verify it now so they aren't bounced to the verification notice.
        InvitationEmailVerification::attempt($request->user(), $request->session());

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(route('app.landing'));
    }
}
