<?php

declare(strict_types=1);

namespace App\Domains\Files\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $file_item_id
 * @property int|null $company_parent_id
 * @property int|null $shared_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read FileItem $fileItem
 * @property-read FileItem|null $companyParent
 * @property-read User|null $sharedBy
 */
class CompanyFileLink extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['workspace_id', 'file_item_id', 'company_parent_id', 'shared_by_user_id'];

    /**
     * Share/unshare are auditable events on the company side — a Customer
     * Admin dashboard filtering log_name = 'company-files' gets a clean
     * feed of who linked what in, without the noise of every rename/upload
     * on the underlying FileItems.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['workspace_id', 'file_item_id', 'company_parent_id', 'shared_by_user_id'])
            ->dontLogEmptyChanges()
            ->useLogName('company-files');
    }

    /**
     * Live in the central DB alongside file_items and tenants — stancl/tenancy
     * swaps the default connection to the tenant schema inside the request
     * lifecycle, and links have no business following that swap.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'workspace_id');
    }

    public function fileItem(): BelongsTo
    {
        return $this->belongsTo(FileItem::class);
    }

    public function companyParent(): BelongsTo
    {
        return $this->belongsTo(FileItem::class, 'company_parent_id');
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'file_item_id' => 'integer',
            'company_parent_id' => 'integer',
            'shared_by_user_id' => 'integer',
        ];
    }
}
