<?php

namespace App\Domains\Auth\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        // Normally a fresh sign-up is unverified and lands on the verification
        // notice. An invitee whose email was auto-verified during registration
        // (CreateNewUser → InvitationEmailVerification) skips it and goes to the
        // landing router, which finishes joining them to the inviting workspace.
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('app.landing')
            : redirect()->route('verification.notice');
    }
}
