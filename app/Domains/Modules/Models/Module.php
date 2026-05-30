<?php

declare(strict_types=1);

namespace App\Domains\Modules\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered domain module. Platform-global (not workspace-scoped): toggling
 * a module on/off affects every workspace. The source of truth for "is this
 * module enabled" — read via ModuleRegistry, which caches the map.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $enabled
 * @property string|null $morph_alias
 */
class Module extends Model
{
    protected $fillable = [
        'key',
        'name',
        'enabled',
        'morph_alias',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Pinned to the central connection — modules live alongside app settings in
     * the one shared database.
     */
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }
}
