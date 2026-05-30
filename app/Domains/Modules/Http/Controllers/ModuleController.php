<?php

declare(strict_types=1);

namespace App\Domains\Modules\Http\Controllers;

use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform admin for the module registry: list modules with live stats, toggle
 * them on/off, and purge a module's data ("delete all"). Super-admin only (the
 * route group enforces it).
 */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Modules/Index', [
            'modules' => $this->registry->all(),
        ]);
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $module->update(['enabled' => $data['enabled']]);
        $this->registry->forget();

        activity('modules')
            ->performedOn($module)
            ->withProperties(['enabled' => $module->enabled])
            ->event($module->enabled ? 'enabled' : 'disabled')
            ->log($module->enabled ? 'Enabled module' : 'Disabled module');

        return back()->with('success', __('admin_modules.toggled', ['name' => $module->name]));
    }

    public function purge(Module $module): RedirectResponse
    {
        $count = $this->registry->purge($module);

        activity('modules')
            ->performedOn($module)
            ->withProperties(['purged' => $count])
            ->event('purged')
            ->log('Purged module data');

        return back()->with('success', __('admin_modules.purged', ['count' => $count, 'name' => $module->name]));
    }
}
