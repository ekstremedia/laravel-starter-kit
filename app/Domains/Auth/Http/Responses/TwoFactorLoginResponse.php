<?php

namespace App\Domains\Auth\Http\Responses;

use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Post two-factor-challenge response. Fortify routes 2FA-enabled logins here
 * instead of through LoginResponse, so without this binding a 2FA user arriving
 * via an invitation would never get the invite-based email auto-verification.
 *
 * The behaviour is identical to LoginResponse (auto-verify a matching invitee,
 * then unverified → verification notice, else the landing router), so we simply
 * inherit it — keeping the two login paths in lockstep.
 */
class TwoFactorLoginResponse extends LoginResponse implements TwoFactorLoginResponseContract {}
