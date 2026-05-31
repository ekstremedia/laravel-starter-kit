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
 * Workspace-admin settings for modules. A workspace admin can, for THIS
 * workspace only: turn a (platform-enabled) module on/off, and override its
 * optional features (files / log). Clearing an override re-inherits the platform
 * default. Disabling a parent module cascades to its grouped children. The route
 * group enforces workspace.admin; platform-wide enable/disable stays super-admin
 * only (see /admin/modules).
 */
class WorkspaceModuleController extends Controller
{
    use BroadcastsResourceChanges;

    /** The `feature` value that targets a module's own enabled state (vs. a FEATURE_KEY). */
    private const ENABLED_TARGET = 'enabled';

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function edit(Request $request): Response
    {
        $workspace = $this->tenant($request);

        $resolved = $this->registry->featuresFor($workspace);
        $overrides = WorkspaceModuleFeature::query()
            ->where('workspace_id', $workspace->id)
            ->get();
        // Keyed by module id. `features` defaults to [] per lookup; an `enabled`
        // override is "present" only when the row's enabled is non-null (isset).
        /** @var array<int, array<string, bool>|null> $featureOverrides */
        $featureOverrides = $overrides->pluck('features', 'module_id')->all();
        /** @var array<int, bool|null> $enabledOverrides */
        $enabledOverrides = $overrides->pluck('enabled', 'module_id')->all();

        // All platform-enabled modules — the workspace can toggle within those.
        $rows = Module::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (Module $module) use ($resolved, $featureOverrides, $enabledOverrides): array {
                $featureOverride = $featureOverrides[$module->id] ?? [];

                $features = [];
                foreach (ModuleRegistry::FEATURE_KEYS as $feature) {
                    if (! $this->registry->supports($module, $feature)) {
                        continue;
                    }
                    $features[] = [
                        'key' => $feature,
                        'platform' => $this->registry->platformFeature($module, $feature),
                        'effective' => (bool) ($resolved[$module->key][$feature] ?? false),
                        'overridden' => array_key_exists($feature, $featureOverride),
                    ];
                }

                return [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'parent_key' => $module->parent_key,
                    'enabled' => [
                        'effective' => (bool) ($resolved[$module->key]['enabled'] ?? false),
                        'platform' => (bool) $module->enabled,
                        // isset() is false for both an absent row and a null enabled.
                        'overridden' => isset($enabledOverrides[$module->id]),
                    ],
                    'features' => $features,
                ];
            });

        // Group children under their parent. A child whose parent isn't itself
        // listed (e.g. parent disabled platform-wide) falls back to top-level so
        // it never silently disappears.
        $presentKeys = $rows->pluck('key')->all();
        $childrenByParent = $rows
            ->filter(fn (array $m): bool => $m['parent_key'] !== null && in_array($m['parent_key'], $presentKeys, true))
            ->groupBy('parent_key');

        $modules = $rows
            ->filter(fn (array $m): bool => $m['parent_key'] === null || ! in_array($m['parent_key'], $presentKeys, true))
            ->map(function (array $m) use ($childrenByParent): array {
                $m['children'] = $childrenByParent->get($m['key'], collect())->values()->all();

                return $m;
            })
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
            'feature' => ['required', Rule::in([self::ENABLED_TARGET, ...ModuleRegistry::FEATURE_KEYS])],
            'enabled' => ['required', 'boolean'],
        ]);

        $row = WorkspaceModuleFeature::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'module_id' => $module->id,
        ]);

        if ($data['feature'] === self::ENABLED_TARGET) {
            // A workspace can only toggle a platform-enabled module — it can't
            // resurrect one a super admin turned off platform-wide.
            abort_unless($module->enabled, 422);
            $row->enabled = $data['enabled'];
        } else {
            // Can't override a feature the module's code doesn't ship.
            abort_unless($this->registry->supports($module, $data['feature']), 422);
            $features = $row->features ?? [];
            $features[$data['feature']] = $data['enabled'];
            $row->features = $features;
        }

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
