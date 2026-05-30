<?php

declare(strict_types=1);

namespace App\Domains\EquipmentCategory\Models;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Workspaces\Models\Concerns\BelongsToWorkspace;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * EquipmentCategory — the demo "related entity" + the reference *lean* module.
 *
 * It demonstrates two things the Equipment module deliberately does not:
 *   1. A real relation — Equipment belongsTo one of these; this hasMany Equipment
 *      (FK equipment.equipment_category_id). The template for future relations
 *      (Car → Wheels, …).
 *   2. Composable features — this module owns NO files (no HasFiles/FileOwner,
 *      no cover, no media), proving a module can opt out of file ownership while
 *      keeping the rest of the standard (datatable, CRUD, trash, export, Log).
 *
 * Whether the Log is surfaced is governed at runtime by the module-feature
 * registry (see ModuleRegistry::featuresFor / the `modules` table capabilities),
 * not hard-wired here.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $color
 * @property string|null $description
 * @property-read Workspace $workspace
 * @property-read Collection<int, Equipment> $equipment
 */
class EquipmentCategory extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'equipment_categories';

    protected $fillable = [
        'workspace_id',
        'name',
        'color',
        'description',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
    ];

    /**
     * Pinned to the central connection — categories live alongside equipment in
     * the one shared database.
     */
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'color', 'description'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('equipment_category');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /**
     * The reverse side of the demo relation: every Equipment filed under this
     * category. Cleared (not cascaded) when the category is deleted — the FK is
     * nullOnDelete, so equipment survives and falls back to "uncategorised".
     *
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'equipment_category_id');
    }
}
