<?php

namespace App\Domains\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * The notification bell fetches this with `Accept: application/json` and
     * gets a compact recent slice; a direct browser navigation (no JSON accept)
     * renders the full Inertia notifications page instead of dumping raw JSON.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        $map = fn ($n) => [
            'id' => $n->id,
            'type' => class_basename($n->type),
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'unread_count' => $user->unreadNotifications()->count(),
                'recent' => $user->notifications()->limit(20)->get()->map($map),
            ]);
        }

        return Inertia::render('Notifications/Index', [
            'notifications' => $user->notifications()->limit(50)->get()->map($map),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->delete();

        return back();
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $request->user()->notifications()->delete();

        return back();
    }
}
