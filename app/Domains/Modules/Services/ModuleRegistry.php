<?php

declare(strict_types=1);

namespace App\Domains\Modules\Services;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Modules\Models\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

/**
 * Single source of truth for "which domain modules are enabled" plus per-module
 * statistics and purging. Resolved as a singleton so the enabled-map is read
 * once per request (it gates route registration AND the Inertia share, both of
 * which fire every request).
 */
class ModuleRegistry
{
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
     * Clear the per-request memo after a toggle so a follow-up read in the same
     * request reflects the change.
     */
    public function forget(): void
    {
        $this->memo = null;
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
            ->map(function (Module $module) use ($storage): array {
                $bytes = (int) ($storage[$module->morph_alias]['bytes'] ?? 0);
                $fileCount = (int) ($storage[$module->morph_alias]['file_count'] ?? 0);

                return [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'enabled' => $module->enabled,
                    'morph_alias' => $module->morph_alias,
                    ...$this->recordCounts($module),
                    'storage_used_bytes' => $bytes,
                    'file_count' => $fileCount,
                ];
            })
            ->all();
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
        $query->get()->each(function (Model $row) use (&$count, $softDeletes): void {
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
        ];
    }
}
