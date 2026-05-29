<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Files\Models\FileItem;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        /** @var Workspace|null $customer */
        $customer = $request->attributes->get('customer') ?? app(WorkspaceContext::class)->current();

        $memberCount = $customer?->users()->count() ?? 0;

        $filesStats = null;
        if ($customer?->files_feature_enabled) {
            // `file_items` carries `workspace_id`. Without that filter a user
            // who owns files in two customers would see their *combined*
            // count on each customer's dashboard — surfacing bytes that
            // belong to another tenant in this customer's UI.
            $filesStats = [
                'count' => FileItem::query()
                    ->where('workspace_id', $customer->getKey())
                    ->where('user_id', $user->getKey())
                    ->where('type', 'file')
                    ->count(),
                'bytes' => (int) FileItem::query()
                    ->where('workspace_id', $customer->getKey())
                    ->where('user_id', $user->getKey())
                    ->where('type', 'file')
                    ->sum('size'),
            ];
        }

        $chatStats = null;
        if (config('chat.enabled', true)) {
            $chatStats = [
                'unread' => $user->unreadMessagesCount(),
            ];
        }

        // Customer-scoped activity: stamped via the `Activity::creating`
        // hook in AppServiceProvider, so we filter directly on `workspace_id`.
        // A user who's Admin on A and plain User on B would otherwise
        // surface B's actions on A's dashboard — a causer-id IN (members)
        // filter can't separate them because the same user is in both
        // member lists. `activity_log` lives on the landlord schema; pin
        // to central since stancl swaps the default connection once
        // tenancy initializes.
        $activity = [];
        if ($customer !== null) {
            $centralConnection = (string) config('workspaces.database.central_connection');
            $activity = Activity::on($centralConnection)
                ->where('workspace_id', $customer->getKey())
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Activity $a) => [
                    'id' => $a->id,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'description' => $a->description,
                    'event' => $a->event,
                    'log_name' => $a->log_name,
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Dashboard', [
            'memberCount' => $memberCount,
            'filesStats' => $filesStats,
            'chatStats' => $chatStats,
            'activity' => $activity,
        ]);
    }
}
