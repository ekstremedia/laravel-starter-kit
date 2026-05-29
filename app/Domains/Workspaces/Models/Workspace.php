<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Models;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\Concerns\HasFiles;
use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $headline
 * @property string|null $about
 * @property string|null $location
 * @property string|null $website
 * @property string $status
 * @property bool $files_feature_enabled
 * @property bool $company_files_enabled
 * @property int|null $storage_quota_bytes
 * @property int $storage_used_bytes
 * @property int|null $default_member_storage_bytes
 */
class Workspace extends Model implements FileOwner
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasFiles;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files_feature_enabled' => 'boolean',
            'company_files_enabled' => 'boolean',
            'storage_quota_bytes' => 'integer',
            'storage_used_bytes' => 'integer',
            'default_member_storage_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user', 'workspace_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Native company-scope FileItems belonging to this tenant (folders + files
     * uploaded directly to the company area).
     *
     * @return HasMany<FileItem, $this>
     */
    public function companyFiles(): HasMany
    {
        return $this->hasMany(FileItem::class, 'workspace_id')->where('scope', FileItem::SCOPE_COMPANY);
    }

    /**
     * @return HasMany<CompanyFileLink, $this>
     */
    public function companyFileLinks(): HasMany
    {
        return $this->hasMany(CompanyFileLink::class, 'workspace_id');
    }

    /**
     * Workspace-owned files: a member with the right permission can manage them.
     * Spatie's team scope is set to this tenant before checking so the
     * permission resolves against the user's role *in this customer*.
     */
    public function canManageFiles(User $user, ?Workspace $workspace = null): bool
    {
        if ($user->isSuperAdmin() || $user->can('manage all files')) {
            return true;
        }

        if (! $user->belongsToCustomer($this)) {
            return false;
        }

        return $this->checkScopedPermission($user, 'manage company files');
    }

    public function canViewFiles(User $user, ?Workspace $workspace = null): bool
    {
        if ($user->isSuperAdmin() || $user->can('manage all files')) {
            return true;
        }

        if (! $user->belongsToCustomer($this)) {
            return false;
        }

        return $this->checkScopedPermission($user, 'view company files')
            || $this->canManageFiles($user, $this);
    }

    /**
     * Run a Spatie permission check with this tenant active as the team scope,
     * then restore the previous scope. Avoids leaking the current request's
     * team id into authorization questions about *this* tenant.
     */
    private function checkScopedPermission(User $user, string $permission): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($this->getKey());
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->can($permission);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
