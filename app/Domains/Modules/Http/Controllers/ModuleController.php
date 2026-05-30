<?php

declare(strict_types=1);

namespace App\Domains\Modules\Http\Controllers;

use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
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
    use BroadcastsResourceChanges;

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
            // Platform feature toggles (files/log). Optional — only the keys the
            // module's code supports are applied; the rest are ignored.
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ]);

        $module->enabled = $data['enabled'];

        if (array_key_exists('features', $data)) {
            $features = $module->features ?? [];
            foreach (ModuleRegistry::FEATURE_KEYS as $feature) {
                // Never let a feature be toggled on past what the code ships.
                if ($this->registry->supports($module, $feature) && array_key_exists($feature, $data['features'])) {
                    $features[$feature] = (bool) $data['features'][$feature];
                }
            }
            $module->features = $features;
        }

        $module->save();
        $this->registry->forget();

        activity('modules')
            ->performedOn($module)
            ->withProperties(['enabled' => $module->enabled, 'features' => $module->features])
            ->event($module->enabled ? 'enabled' : 'disabled')
            ->log($module->enabled ? 'Enabled module' : 'Disabled module');

        $this->broadcastResourceChanged('modules', 'updated', $module->id);

        // The toggle itself is the feedback — no success toast for a routine flip.
        return back();
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
