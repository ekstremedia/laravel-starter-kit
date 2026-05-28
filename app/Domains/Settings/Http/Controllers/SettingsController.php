<?php

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Users\Models\UserSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Update one or more settings for the authenticated user.
     *
     * Accepts a partial settings object — only the provided keys are updated.
     * Unknown keys are ignored to prevent storing arbitrary data.
     */
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $allowed = array_keys(UserSetting::$defaults);

        $validated = $request->validate(
            collect($allowed)->mapWithKeys(fn ($key) => [$key => 'sometimes'])->toArray()
        );

        // Reject any key not in the allowed list
        $partial = array_intersect_key($validated, array_flip($allowed));

        $request->user()->settings()->merge($partial);

        if ($request->hasHeader('X-Inertia')) {
            return back(status: 303);
        }

        return response()->json(['settings' => $request->user()->settings()->resolved()]);
    }
}
