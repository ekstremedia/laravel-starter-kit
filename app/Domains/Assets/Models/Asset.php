<?php

declare(strict_types=1);

namespace App\Domains\Assets\Models;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Models\Concerns\HasFileQuota;
use App\Domains\Files\Models\Concerns\HasFiles;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Demo file-owning entity. Each Asset belongs to a customer (Tenant) and owns
 * its own FileItem document tree via the polymorphic FileOwner contract —
 * the same mechanism that powers personal (User) and company (Tenant) files.
 *
 * This is the reference implementation for "attach files to any entity": a new
 * entity (Vehicle, Medicine, Building…) just mirrors this class — adopt
 * HasFiles + HasFileQuota, implement FileOwner, register the morph alias in
 * AppServiceProvider, and add it to config('files.allowed_owner_types').
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $category
 * @property string|null $serial
 * @property string|null $notes
 * @property int|null $file_quota_bytes
 * @property int $storage_used_bytes
 * @property-read Tenant $tenant
 */
class Asset extends Model implements FileOwner
{
    use HasFactory;
    use HasFileQuota;
    use HasFiles;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'serial',
        'notes',
        'file_quota_bytes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'file_quota_bytes' => 'integer',
        'storage_used_bytes' => 'integer',
    ];

    /**
     * Pinned to the central connection — assets live alongside users/tenants
     * in the central schema, not inside per-tenant schemas.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'serial', 'notes', 'file_quota_bytes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('assets');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * File access mirrors company-file semantics: a member of the asset's
     * customer with the right role (or a super-admin / "manage all files"
     * holder) can manage the asset's documents. Delegating to the Tenant
     * reuses its team-scoped permission resolution.
     */
    public function canManageFiles(User $user, ?Tenant $tenant = null): bool
    {
        return $this->tenant->canManageFiles($user, $tenant ?? $this->tenant);
    }

    public function canViewFiles(User $user, ?Tenant $tenant = null): bool
    {
        return $this->tenant->canViewFiles($user, $tenant ?? $this->tenant);
    }
}
