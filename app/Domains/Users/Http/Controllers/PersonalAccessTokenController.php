<?php

namespace App\Domains\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User-facing CRUD for Sanctum personal access tokens.
 *
 * Tokens are scoped to the authenticated user: `auth()->user()->tokens()`
 * already encapsulates ownership, and Laravel's plain-text token is surfaced
 * exactly once via a flash message the SPA renders and then forgets.
 */
class PersonalAccessTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/ApiTokens', [
            'tokens' => auth()->user()->tokens()
                ->latest()
                ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'abilities' => $t->abilities,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['array'],
            'abilities.*' => ['string', 'max:60'],
        ]);

        $abilities = ! empty($data['abilities']) ? $data['abilities'] : ['*'];
        $token = auth()->user()->createToken($data['name'], $abilities);

        activity('auth')->event('token_created')->causedBy(auth()->user())->withProperties(['name' => $data['name']])->log('API token created');

        // flash() — not session()->put — so it round-trips exactly once.
        return back()->with('new_token', $token->plainTextToken);
    }

    public function destroy(int $id): RedirectResponse
    {
        $token = auth()->user()->tokens()->where('id', $id)->firstOrFail();
        $token->delete();

        activity('auth')->event('token_revoked')->causedBy(auth()->user())->withProperties(['name' => $token->name])->log('API token revoked');

        return back();
    }
}
