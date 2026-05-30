<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Files\Models\FileItem;
use App\Domains\Modules\Services\DashboardWidgetRegistry;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace') ?? app(WorkspaceContext::class)->current();

        $memberCount = $workspace?->users()->count() ?? 0;

        $filesStats = null;
        if ($workspace?->files_feature_enabled) {
            // `file_items` carries `workspace_id`. Without that filter a user
            // who owns files in two workspaces would see their *combined*
            // count on each workspace's dashboard — surfacing bytes that
            // belong to another tenant in this workspace's UI.
            $filesStats = [
                'count' => FileItem::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('user_id', $user->getKey())
                    ->where('type', 'file')
                    ->count(),
                'bytes' => (int) FileItem::query()
                    ->where('workspace_id', $workspace->getKey())
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

        // Per-user, customizable module widgets (default on). Only enabled
        // widgets' data is computed; the rest are listed for the customize panel.
        $hidden = (array) ($user->settings()->resolved()['dashboard_hidden_widgets'] ?? []);
        $widgets = $workspace !== null
            ? app(DashboardWidgetRegistry::class)->forUser($workspace, $user, $hidden)
            : [];

        return Inertia::render('Dashboard', [
            'memberCount' => $memberCount,
            'filesStats' => $filesStats,
            'chatStats' => $chatStats,
            'widgets' => $widgets,
        ]);
    }
}
