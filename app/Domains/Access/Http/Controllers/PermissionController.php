<?php

namespace App\Domains\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    use BroadcastsResourceChanges;

    public function index(): Response
    {
        return Inertia::render('Admin/Permissions/Index', [
            'permissions' => Permission::orderBy('name')->withCount('roles')->get()->map(fn ($p) => $this->rowShape($p)),
        ]);
    }

    public function liveRow(Permission $permission): JsonResponse
    {
        $permission->loadCount('roles');

        return response()->json($this->rowShape($permission));
    }

    /**
     * Shape a single permission into the list-row array used by index().
     */
    private function rowShape(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'guard_name' => $permission->guard_name,
            'roles_count' => $permission->roles_count,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:permissions,name'],
        ]);

        $permission = Permission::create(['name' => $data['name']]);

        activity('permission')
            ->performedOn($permission)
            ->event('created')
            ->log("Created permission {$permission->name}");

        $this->broadcastResourceChanged('permissions', 'created', $permission->id, null);

        return back()->with('success', __('flash.permissions.created'));
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        $id = $permission->id;
        $name = $permission->name;
        $permission->delete();

        activity('permission')
            ->withProperties(['name' => $name])
            ->event('deleted')
            ->log("Deleted permission {$name}");

        $this->broadcastResourceChanged('permissions', 'deleted', $id, null);

        return back()->with('success', __('flash.permissions.deleted'));
    }
}
