<?php

declare(strict_types=1);

namespace App\Domains\Modules\Services;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Models\WorkspaceModuleFeature;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Single source of truth for "which domain modules are enabled" plus per-module
 * statistics and purging. Resolved as a singleton so the enabled-map is read
 * once per request (it gates route registration AND the Inertia share, both of
 * which fire every request).
 */
class ModuleRegistry
{
    /** The optional, per-module-toggleable features. A module's `capabilities` say which of these its code ships. */
    public const FEATURE_KEYS = ['files', 'log'];

    /** @var array<string, bool>|null Per-request memo of the enabled map. */
    private ?array $memo = null;

    public function __construct(private readonly StorageUsageService $usage) {}

    /**
     * Module-key → enabled map. Cached for the request. Null-safe: before the
     * `modules` table exists (fresh install, mid-migrate) or if it can't be
     * read, falls back to config defaults so routes/boot never blow up.
     *
     * @return array<string, bool>
     */
    public function enabledMap(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $this->memo = Module::query()->pluck('enabled', 'key')
                ->map(fn ($v) => (bool) $v)
                ->all();
        } catch (Throwable) {
            $this->memo = $this->configDefaults();
        }

        return $this->memo;
    }

    public function isEnabled(string $key): bool
    {
        return $this->enabledMap()[$key] ?? ($this->configDefaults()[$key] ?? false);
    }

    /**
     * Whether a module is effectively enabled FOR A WORKSPACE — the platform
     * flag, the workspace's own override, and the parent cascade all folded in
     * (the same `enabled` the front end sees). This is what gates a module's
     * routes per workspace; `isEnabled()` stays the platform-only meaning.
     */
    public function moduleEnabled(string $key, ?Workspace $workspace = null): bool
    {
        $map = $this->featuresFor($workspace);

        // Same config fallback as isEnabled(): a module with no DB row yet (fresh
        // install / unseeded) defaults to its config flag rather than "off".
        return array_key_exists($key, $map)
            ? (bool) $map[$key]['enabled']
            : ($this->configDefaults()[$key] ?? false);
    }

    /**
     * Whether a module's feature (e.g. 'files', 'log') is effectively on for the
     * given workspace — the same resolution the front end sees. Lets controllers
     * skip work for a disabled feature (e.g. not loading the activity Log).
     */
    public function featureEnabled(string $key, string $feature, ?Workspace $workspace = null): bool
    {
        return (bool) ($this->featuresFor($workspace)[$key][$feature] ?? false);
    }

    /**
     * Clear the per-request memo after a toggle so a follow-up read in the same
     * request reflects the change.
     */
    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * Whether a module's code ships a given feature (the toggle ceiling).
     */
    public function supports(Module $module, string $feature): bool
    {
        return (bool) (($module->capabilities ?? [])[$feature] ?? false);
    }

    /**
     * The platform-level (super-admin) effective state of a feature: the
     * `modules.features` toggle, clamped to what the code supports. Defaults to
     * "on where supported" when the toggle is unset (legacy rows).
     */
    public function platformFeature(Module $module, string $feature): bool
    {
        if (! $this->supports($module, $feature)) {
            return false;
        }

        return (bool) (($module->features ?? [])[$feature] ?? true);
    }

    /**
     * Resolve every module's effective {enabled, <feature>...} for a workspace.
     * For each axis a per-workspace override (workspace_module_features) wins
     * over the platform default, which wins over the capability default:
     *   - enabled: platform `modules.enabled` is the ceiling; the workspace can
     *     turn an enabled module off (or back on), and a module is forced off
     *     whenever its parent (parent_key) is off — the cascade.
     *   - features: the per-workspace toggle wins over the platform feature,
     *     clamped to what the code ships (capabilities).
     * This is the shape shared to the front end as the `modules` prop and read
     * by the sidebar + page conditional rendering + per-workspace route gating.
     *
     * Null-safe: before the tables exist (fresh install) falls back to a
     * conservative config-derived map.
     *
     * @return array<string, array<string, bool>>
     */
    public function featuresFor(?Workspace $workspace): array
    {
        try {
            $modules = Module::query()->get();

            $overrides = $workspace
                ? WorkspaceModuleFeature::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->get()
                    : collect();
            // Keyed by module id. `enabled` is bool|null (null = inherit);
            // `features` defaults to [] per lookup (absent = no override).
            /** @var array<int, bool|null> $enabledOverrides */
            $enabledOverrides = $overrides->pluck('enabled', 'module_id')->all();
            /** @var array<int, array<string, bool>|null> $featureOverrides */
            $featureOverrides = $overrides->pluck('features', 'module_id')->all();

            // Pass 1: each module's OWN effective enabled — the platform flag
            // (ceiling) AND the per-workspace override (null = inherit). Keyed by
            // module key so pass 2 can fold in a parent's state.
            $ownEnabled = [];
            foreach ($modules as $module) {
                $wsEnabled = $enabledOverrides[$module->getKey()] ?? null;
                $ownEnabled[$module->key] = (bool) $module->enabled && ($wsEnabled ?? true);
            }

            // Pass 2: build the map, cascading a parent's own-effective onto its
            // children and resolving the feature toggles.
            $map = [];
            foreach ($modules as $module) {
                $featureOverride = $featureOverrides[$module->getKey()] ?? [];

                $enabled = $ownEnabled[$module->key];
                if ($module->parent_key !== null && array_key_exists($module->parent_key, $ownEnabled)) {
                    $enabled = $enabled && $ownEnabled[$module->parent_key];
                }

                $entry = ['enabled' => $enabled];
                foreach (self::FEATURE_KEYS as $feature) {
                    if (! $this->supports($module, $feature)) {
                        $entry[$feature] = false;

                        continue;
                    }
                    $entry[$feature] = array_key_exists($feature, $featureOverride)
                        ? (bool) $featureOverride[$feature]
                        : $this->platformFeature($module, $feature);
                }
                $map[$module->key] = $entry;
            }

            return $map;
        } catch (Throwable) {
            $fallback = [];
            foreach ($this->configDefaults() as $key => $enabled) {
                $fallback[$key] = ['enabled' => $enabled, 'files' => false, 'log' => false];
            }

            return $fallback;
        }
    }

    /**
     * All modules with their live statistics, for the /admin/modules page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $storage = collect($this->usage->systemBreakdownByOwnerType())->keyBy('type');

        return Module::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Module $module): array => $this->row($module, $storage))
            ->all();
    }

    /**
     * Shape a single module into the /admin/modules list-row: identity, the
     * platform enabled flag, per-feature {supported, enabled}, record/trashed
     * counts and storage stats. Used by both all() (the index list) and the
     * single-row live-update endpoint.
     *
     * @param  Collection<int|string, array{type: string, file_count: int, bytes: int}>|null  $storage
     *                                                                                                  Pre-fetched system storage breakdown keyed by morph alias. Passed in
     *                                                                                                  by all() to avoid re-querying per row; resolved here when omitted.
     * @return array<string, mixed>
     */
    public function row(Module $module, $storage = null): array
    {
        $storage ??= collect($this->usage->systemBreakdownByOwnerType())->keyBy('type');

        $bytes = (int) ($storage[$module->morph_alias]['bytes'] ?? 0);
        $fileCount = (int) ($storage[$module->morph_alias]['file_count'] ?? 0);

        // Per-feature {supported, enabled} so the admin UI can render a
        // toggle only where the code ships the capability.
        $features = [];
        foreach (self::FEATURE_KEYS as $feature) {
            $features[$feature] = [
                'supported' => $this->supports($module, $feature),
                'enabled' => $this->platformFeature($module, $feature),
            ];
        }

        return [
            'id' => $module->id,
            'key' => $module->key,
            'name' => $module->name,
            'enabled' => $module->enabled,
            'morph_alias' => $module->morph_alias,
            'features' => $features,
            ...$this->recordCounts($module),
            'storage_used_bytes' => $bytes,
            'file_count' => $fileCount,
        ];
    }

    /**
     * Record/trashed counts for a module's entity (resolved from its morph
     * alias). Counts across all workspaces (admin context).
     *
     * @return array{record_count: int, trashed_count: int}
     */
    public function recordCounts(Module $module): array
    {
        $class = $module->morph_alias ? Relation::getMorphedModel($module->morph_alias) : null;

        if ($class === null) {
            return ['record_count' => 0, 'trashed_count' => 0];
        }

        $query = $class::query()->withoutGlobalScope('workspace');
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);

        return [
            'record_count' => (clone $query)->count(),
            'trashed_count' => $softDeletes ? (clone $query)->onlyTrashed()->count() : 0,
        ];
    }

    /**
     * Permanently delete every record of a module's entity (the "delete all"
     * admin action). Force-deletes so soft-deleted rows and their file trees go
     * too — the entity's own delete hooks cascade documents.
     */
    public function purge(Module $module): int
    {
        $class = $module->morph_alias ? Relation::getMorphedModel($module->morph_alias) : null;

        if ($class === null) {
            return 0;
        }

        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);
        $query = $class::query()->withoutGlobalScope('workspace');
        if ($softDeletes) {
            $query->withTrashed();
        }

        $count = 0;
        // Stream in bounded batches (lazyById) rather than materializing the
        // whole module dataset, so purging a large module stays memory-safe
        // while each row's delete hooks still fire (cascading its file tree).
        $query->lazyById()->each(function (Model $row) use (&$count, $softDeletes): void {
            // forceDelete on soft-deleting models triggers the entity's own
            // cascade (e.g. Equipment drops its file tree); plain delete otherwise.
            $softDeletes ? $row->forceDelete() : $row->delete();
            $count++;
        });

        return $count;
    }

    /**
     * Config-derived fallback enabled map (one entry per known module config).
     *
     * @return array<string, bool>
     */
    private function configDefaults(): array
    {
        return [
            'equipment' => (bool) config('equipment.enabled', true),
            'equipment_category' => (bool) config('equipment_category.enabled', true),
        ];
    }
}
