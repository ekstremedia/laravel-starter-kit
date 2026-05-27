<?php

namespace App\Domains\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'roles' => Role::with('permissions:id,name')
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'permissions' => $r->permissions->pluck('name')->toArray(),
                    'users_count' => $r->users_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Roles/Edit', [
            'role' => null,
            'permissions' => Permission::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        activity('role')
            ->performedOn($role)
            ->withProperties(['permissions' => $data['permissions'] ?? []])
            ->event('created')
            ->log("Created role {$role->name}");

        return redirect()->route('admin.roles.index')->with('success', __('flash.roles.created'));
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Admin/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ],
            'permissions' => Permission::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name,'.$role->id],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $previousName = $role->name;
        $previousPermissions = $role->permissions->pluck('name')->sort()->values()->all();
        $newPermissions = collect($data['permissions'] ?? [])->sort()->values()->all();

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        activity('role')
            ->performedOn($role)
            ->withProperties([
                'previous' => ['name' => $previousName, 'permissions' => $previousPermissions],
                'current' => ['name' => $role->name, 'permissions' => $newPermissions],
                'permissions_added' => array_values(array_diff($newPermissions, $previousPermissions)),
                'permissions_removed' => array_values(array_diff($previousPermissions, $newPermissions)),
            ])
            ->event('updated')
            ->log("Updated role {$role->name}");

        return redirect()->route('admin.roles.index')->with('success', __('flash.roles.updated'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $name = $role->name;
        $permissions = $role->permissions->pluck('name')->all();
        $role->delete();

        activity('role')
            ->withProperties(['name' => $name, 'permissions' => $permissions])
            ->event('deleted')
            ->log("Deleted role {$name}");

        return redirect()->route('admin.roles.index')->with('success', __('flash.roles.deleted'));
    }
}
