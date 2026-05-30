<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Models\WorkspaceModuleFeature;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-admin settings for module features. A workspace admin can override
 * the platform default for a module's optional features (files / log) for THIS
 * workspace only; clearing the override re-inherits the platform default. The
 * route group enforces workspace.admin. Platform enable/disable stays super-admin
 * only (see /admin/modules).
 */
class WorkspaceModuleController extends Controller
{
    use BroadcastsResourceChanges;

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function edit(Request $request): Response
    {
        $workspace = $this->tenant($request);

        $resolved = $this->registry->featuresFor($workspace);
        /** @var array<int, array<string, bool>|null> $overrideMap */
        $overrideMap = WorkspaceModuleFeature::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('features', 'module_id')
            ->all();

        $modules = Module::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (Module $module) use ($resolved, $overrideMap): array {
                $override = $overrideMap[$module->id] ?? [];

                $features = [];
                foreach (ModuleRegistry::FEATURE_KEYS as $feature) {
                    if (! $this->registry->supports($module, $feature)) {
                        continue;
                    }
                    $features[] = [
                        'key' => $feature,
                        'platform' => $this->registry->platformFeature($module, $feature),
                        'effective' => (bool) ($resolved[$module->key][$feature] ?? false),
                        'overridden' => array_key_exists($feature, $override),
                    ];
                }

                return [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'features' => $features,
                ];
            })
            // Only modules that ship at least one toggleable feature are relevant.
            ->filter(fn (array $module): bool => $module['features'] !== [])
            ->values()
            ->all();

        // Keyed `module_settings` (not `modules`) so it doesn't shadow the shared
        // `modules` features map the rail/pages read in app mode.
        return Inertia::render('Workspace/ModuleSettings', [
            'module_settings' => $modules,
        ]);
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $workspace = $this->tenant($request);

        $data = $request->validate([
            'feature' => ['required', Rule::in(ModuleRegistry::FEATURE_KEYS)],
            'enabled' => ['required', 'boolean'],
        ]);

        // Can't override a feature the module's code doesn't ship.
        abort_unless($this->registry->supports($module, $data['feature']), 422);

        $row = WorkspaceModuleFeature::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'module_id' => $module->id,
        ]);
        $features = $row->features ?? [];
        $features[$data['feature']] = $data['enabled'];
        $row->features = $features;
        $row->save();

        $this->broadcastResourceChanged('module_settings', 'updated', $module->id, $workspace->id);

        // The toggle + "Overridden" badge are the feedback — no success toast.
        return back();
    }

    public function reset(Request $request, Module $module): RedirectResponse
    {
        $workspace = $this->tenant($request);

        WorkspaceModuleFeature::query()
            ->where('workspace_id', $workspace->id)
            ->where('module_id', $module->id)
            ->delete();

        $this->broadcastResourceChanged('module_settings', 'updated', $module->id, $workspace->id);

        return back();
    }

    private function tenant(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $slug = config('workspaces.default_workspace_slug');
        $fallback = $slug ? Workspace::query()->where('slug', $slug)->first() : null;
        abort_if($fallback === null, 404);

        return $fallback;
    }
}
