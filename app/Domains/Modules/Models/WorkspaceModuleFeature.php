<?php

declare(strict_types=1);

namespace App\Domains\Modules\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's override of a module's optional features (files / log). Absent
 * for a (workspace, module) pair means "inherit the platform default". Resolved
 * by ModuleRegistry::featuresFor().
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $module_id
 * @property array<string, bool>|null $features
 */
class WorkspaceModuleFeature extends Model
{
    protected $fillable = [
        'workspace_id',
        'module_id',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    /**
     * Pinned to the central connection — alongside modules / workspaces.
     */
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }
}
