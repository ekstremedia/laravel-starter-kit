<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $activity = Activity::query()
            ->where('causer_id', $user->getKey())
            ->where('causer_type', $user->getMorphClass())
            ->latest()
            ->limit(10)
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

        return Inertia::render('Home', [
            'userDetail' => [
                'id' => $user->getKey(),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
                'is_super_admin' => $user->isSuperAdmin(),
                // `/home` is a central route (no active workspace), so a plain
                // `getRoleNames()` call would resolve against a null team id
                // and always come back empty — the meaningful answer is the
                // user's per-workspace role map.
                'workspace_roles' => $this->workspaceRolesFor($user),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'activity' => $activity,
        ]);
    }

    /**
     * @return array<int, array{id:int, name:string, slug:string, roles:array<int,string>}>
     */
    private function workspaceRolesFor(User $user): array
    {
        /** @var array<int, Workspace> $workspaces */
        $workspaces = $user->workspaces()->orderBy('name')->get(['workspaces.id', 'name', 'slug'])->all();
        if ($workspaces === []) {
            return [];
        }

        $mhr = config('permission.table_names.model_has_roles');
        $rolesTable = config('permission.table_names.roles');
        $teamKey = config('permission.column_names.team_foreign_key');

        $central = (string) config('workspaces.database.central_connection');
        $rows = DB::connection($central)->table($mhr)
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$mhr}.role_id")
            ->where("{$mhr}.model_type", (new User)->getMorphClass())
            ->where("{$mhr}.model_id", $user->getKey())
            ->whereIn("{$mhr}.{$teamKey}", array_map(fn (Workspace $c) => $c->id, $workspaces))
            ->get([$mhr.'.'.$teamKey.' as team_id', $rolesTable.'.name as name']);

        $rolesByTeam = [];
        foreach ($rows as $row) {
            $rolesByTeam[$row->team_id][] = $row->name;
        }

        return array_map(fn (Workspace $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'roles' => array_values(array_unique($rolesByTeam[$c->id] ?? [])),
        ], $workspaces);
    }
}
