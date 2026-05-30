<?php

declare(strict_types=1);

namespace App\Domains\Equipment\Models;

use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Models\Concerns\HasFiles;
use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Concerns\BelongsToWorkspace;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The Equipment ("Utstyr") module — the reference file-owning domain entity.
 * Each Equipment belongs to a workspace (Workspace) and owns its own FileItem
 * document tree via the polymorphic FileOwner contract — the same mechanism
 * that powers personal (User) and company (Workspace) files.
 *
 * This is the canonical template for "a workspace-scoped module that owns
 * files": a new module (Car, Medicine, Building…) mirrors this class — adopt
 * BelongsToWorkspace + HasFiles, implement FileOwner, register the morph alias
 * in AppServiceProvider, add it to config('files.allowed_owner_types'), and
 * register a row in the `modules` table (see ModuleSeeder / ModuleRegistry).
 * See docs/adding-a-workspace-entity.md for the full recipe.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property int|null $equipment_category_id
 * @property string|null $serial
 * @property string|null $notes
 * @property int|null $cover_file_item_id the file used as the row thumbnail / "main image"
 * @property-read Workspace $workspace
 * @property-read EquipmentCategory|null $category
 * @property-read FileItem|null $cover
 */
class Equipment extends Model implements FileOwner
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasFiles;
    use LogsActivity;
    use SoftDeletes;

    /**
     * "equipment" is an uncountable noun, so Eloquent's table guess from the
     * class name is unreliable — pin it explicitly.
     */
    protected $table = 'equipment';

    protected $fillable = [
        'workspace_id',
        'name',
        'equipment_category_id',
        'serial',
        'notes',
        'cover_file_item_id',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'equipment_category_id' => 'integer',
        'cover_file_item_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // The delete dialog promises "this also removes its documents", so
        // cascade the owned file tree when an item is deleted — otherwise the
        // FileItems orphan (still counting against storage, pointing at a gone
        // owner). Iterate so each FileItem's own delete hooks run; files use
        // soft deletes, so this trashes them rather than dropping rows.
        static::deleting(function (Equipment $equipment): void {
            // forceDeleting → permanently drop the file tree too; a plain
            // (soft) delete just trashes it so trash/restore can recover both.
            if ($equipment->isForceDeleting()) {
                $equipment->files()->withTrashed()->get()->each->forceDelete();

                return;
            }

            $equipment->files()->get()->each->delete();
        });

        // Mirror image of the cascade above: restoring an item from trash also
        // un-trashes the documents soft-deleted *alongside it*, so a restored
        // item comes back with its files intact — but NOT documents the user had
        // already trashed earlier. The cascade trashes children a hair before
        // the parent row, so include a short window before the parent's
        // deleted_at and leave anything trashed earlier untouched.
        static::restoring(function (Equipment $equipment): void {
            $deletedAt = $equipment->deleted_at;
            if ($deletedAt === null) {
                return;
            }

            $equipment->files()
                ->onlyTrashed()
                ->where('deleted_at', '>=', $deletedAt->copy()->subSeconds(10))
                ->get()
                ->each
                ->restore();
        });
    }

    /**
     * Pinned to the central connection — equipment lives alongside users and
     * workspaces in the one shared database. The pin is vestigial and resolves
     * to the single default connection.
     */
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'equipment_category_id', 'serial', 'notes', 'cover_file_item_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('equipment');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /**
     * The demo relation: the EquipmentCategory this item is filed under (or null
     * when uncategorised). The reverse side is EquipmentCategory::equipment().
     *
     * @return BelongsTo<EquipmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /**
     * The chosen "main" document — used as the row thumbnail when it carries a
     * preview. Null falls back (at render time) to the earliest previewable
     * file, so the first uploaded document is the default cover with no stored
     * state. Cleared automatically if the file is deleted (nullOnDelete FK).
     *
     * @return BelongsTo<FileItem, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(FileItem::class, 'cover_file_item_id');
    }

    /**
     * Document access follows the Equipment module's own capability: a holder of
     * `manage equipment` (Admins + Editors, or a super-admin / "manage all files"
     * holder) can manage the item's documents — not the admin-level
     * `manage company files`, so content editors can run the module. Delegating
     * to the Workspace reuses its team-scoped permission resolution.
     */
    public function canManageFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $this->workspace->canManageEquipment($user, $workspace ?? $this->workspace);
    }

    public function canViewFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $this->workspace->canViewFiles($user, $workspace ?? $this->workspace);
    }
}
