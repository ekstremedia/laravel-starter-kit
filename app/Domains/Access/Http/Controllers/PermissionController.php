<?php

namespace App\Domains\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
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
            'permissions' => Permission::orderBy('name')->withCount('roles')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'guard_name' => $p->guard_name,
                'roles_count' => $p->roles_count,
            ]),
        ]);
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
