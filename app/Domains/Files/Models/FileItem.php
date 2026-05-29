<?php

declare(strict_types=1);

namespace App\Domains\Files\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $user_id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $parent_id
 * @property string $type
 * @property string $scope
 * @property string $name
 * @property string|null $mime_type
 * @property int $size
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read User $creator
 * @property-read User $user
 * @property-read Model|null $owner
 * @property-read FileItem|null $parent
 */
class FileItem extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    public const TYPE_FOLDER = 'folder';

    public const TYPE_FILE = 'file';

    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_COMPANY = 'company';

    public const IMAGE_SIZES = [
        'thumb' => ['width' => 400, 'height' => 400, 'quality' => 80],
        'medium' => ['width' => 1280, 'height' => 1280, 'quality' => 85],
        'large' => ['width' => 2048, 'height' => 2048, 'quality' => 90],
        'xlarge' => ['width' => 4096, 'height' => 4096, 'quality' => 92],
    ];

    protected $fillable = [
        'workspace_id',
        'user_id',
        'owner_type',
        'owner_id',
        'parent_id',
        'type',
        'scope',
        'name',
        'mime_type',
        'size',
        'metadata',
    ];

    /**
     * Audit create/update/delete/restore on FileItems so Customer Admins can
     * see "who uploaded this folder" or "who renamed that file". We log a
     * compact attribute set — the full media blob doesn't belong in an audit
     * trail, and a bare timestamp change (Eloquent's updated_at touch after
     * a related media insert) shouldn't produce a noisy row either.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'scope', 'owner_type', 'owner_id', 'type', 'parent_id', 'size', 'mime_type'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('files');
    }

    /**
     * Pin to the central connection so queries don't follow the tenant schema
     * switch performed by stancl/tenancy middleware — file_items lives in the
     * central DB alongside users and tenants, not inside per-tenant schemas.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'workspace_id');
    }

    /**
     * Polymorphic owner: the model that owns this file (User for personal,
     * Tenant for company, future Building/Customer/etc.). Can be null when
     * the related row was hard-deleted out from under the morph.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who uploaded/created this file. Always a User (column is NOT NULL).
     * Distinct from owner() — a Tenant-owned file is still created by a user.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Backwards-compatible alias for creator(). Existing call-sites that read
     * $item->user keep working until they're migrated to ->creator or ->owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->creator();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<FileItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isFolder(): bool
    {
        return $this->type === self::TYPE_FOLDER;
    }

    public function isCompanyScope(): bool
    {
        return $this->scope === self::SCOPE_COMPANY;
    }

    public function isPersonalScope(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    /**
     * @return HasOne<CompanyFileLink, $this>
     */
    public function companyLink(): HasOne
    {
        return $this->hasOne(CompanyFileLink::class);
    }

    public function isImage(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'image/');
    }

    public function isAudio(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'audio/');
    }

    /**
     * True for files we render inline as text/code instead of downloading.
     */
    public function isTextPreviewable(): bool
    {
        if ($this->isFolder()) {
            return false;
        }
        if ($this->mime_type !== null && str_starts_with($this->mime_type, 'text/')) {
            return true;
        }
        if (in_array((string) $this->mime_type, ['application/json', 'application/xml', 'application/x-yaml'], true)) {
            return true;
        }

        return in_array($this->extension(), (array) config('files.text_extensions', []), true);
    }

    public function isMarkdown(): bool
    {
        return in_array($this->extension(), (array) config('files.markdown_extensions', []), true);
    }

    /**
     * Lowercase file extension derived from the stored name (no leading dot).
     */
    public function extension(): string
    {
        return strtolower((string) pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * True for files that aren't browser-displayable as-is but for which we
     * generate a normalized JPEG preview: camera RAW, TIFF, HEIC/HEIF. Detected
     * by extension OR mime, since RAW usually arrives as octet-stream.
     */
    public function needsImagePreview(): bool
    {
        if ($this->isFolder()) {
            return false;
        }

        $ext = $this->extension();
        $raw = (array) config('files.raw_extensions', []);
        $rasterize = (array) config('files.rasterize_extensions', []);

        if (in_array($ext, $raw, true) || in_array($ext, $rasterize, true)) {
            return true;
        }

        return in_array((string) $this->mime_type, ['image/tiff', 'image/heic', 'image/heif'], true);
    }

    /**
     * True when this item should be treated as an image in the UI — either a
     * genuinely browser-displayable image, or a RAW/TIFF/HEIC that gets a
     * generated JPEG preview.
     */
    public function isPreviewableImage(): bool
    {
        return $this->isImage() || $this->needsImagePreview();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
        $this->addMediaCollection('doc_preview')->singleFile();
        $this->addMediaCollection('video_preview')->singleFile();
        $this->addMediaCollection('video_web')->singleFile();
        // Normalized JPEG rendered from RAW/TIFF/HEIC originals (the browser
        // can't show those). Carries the same size conversions as `file`.
        $this->addMediaCollection('image_preview')->singleFile();
    }

    public function isVideo(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'video/');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media === null || ! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        if ($media->mime_type === 'image/svg+xml') {
            return;
        }

        foreach (self::IMAGE_SIZES as $name => $cfg) {
            $this->addMediaConversion($name)
                ->fit(Fit::Contain, $cfg['width'], $cfg['height'])
                ->format('webp')
                ->quality($cfg['quality'])
                // The generated image_preview JPEG gets the same thumb/medium/
                // large/xlarge ladder as a native image upload.
                ->performOnCollections('file', 'image_preview');
        }
    }

    /**
     * Scope by polymorphic owner — replaces hand-rolled
     * `where('user_id', $u->id)->where('scope', 'personal')` blocks.
     *
     * @param  Builder<FileItem>  $query
     * @return Builder<FileItem>
     */
    public function scopeForOwner($query, Model $owner)
    {
        return $query->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'workspace_id' => 'integer',
            'user_id' => 'integer',
            'owner_id' => 'integer',
            'parent_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * When a folder is soft-deleted, cascade the soft-delete to children too
     * — otherwise they'd remain visible in the parent's listing after the
     * parent disappeared. On force-delete the DB cascade handles it.
     */
    protected static function booted(): void
    {
        static::deleting(function (FileItem $item): void {
            if (! $item->isForceDeleting() && $item->isFolder()) {
                $item->children()->get()->each->delete();
            }
        });
    }
}
