<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DevLoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            (app()->isLocal() || app()->runningUnitTests()) && config('dev.easy_login_enabled'),
            404,
        );

        $user = User::find(1);

        if (! $user) {
            return back()->with('error', 'Easy login user #1 was not found.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('app.landing'));
    }
}
