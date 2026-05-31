<?php

declare(strict_types=1);

namespace App\Domains\Modules\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's override of a module: its enabled state and/or its optional
 * features (files / log). Absent for a (workspace, module) pair means "inherit
 * the platform default"; a present row with `enabled` null still inherits the
 * platform enabled flag. Resolved by ModuleRegistry::featuresFor().
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $module_id
 * @property bool|null $enabled null = inherit platform `modules.enabled`
 * @property array<string, bool>|null $features
 */
class WorkspaceModuleFeature extends Model
{
    protected $fillable = [
        'workspace_id',
        'module_id',
        'enabled',
        'features',
    ];

    protected $casts = [
        // Nullable: Laravel's primitive cast returns null untouched, so a null
        // column stays "inherit" rather than collapsing to false.
        'enabled' => 'boolean',
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
