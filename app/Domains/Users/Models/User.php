<?php

namespace App\Domains\Users\Models;

use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Models\Concerns\HasFiles;
use App\Domains\Files\Models\FileItem;
use App\Domains\Notifications\Notifications\ResetPasswordNotification;
use App\Domains\Notifications\Notifications\VerifyEmailNotification;
use App\Domains\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $banned_at
 * @property Carbon|null $last_login_at
 * @property string $public_id
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $location
 * @property string|null $website
 * @property array<int, string>|null $platform_permissions
 */
// `is_super_admin` is intentionally *not* fillable — it must only be set via
// explicit `forceFill(['is_super_admin' => ...])` or the dedicated setRole
// flow in UserController. Allowing mass-assignment here would let a crafted
// payload on `/admin/users` or `/register` elevate the account without
// going through the SuperAdmin-gated code path.
#[Fillable(['public_id', 'first_name', 'last_name', 'email', 'password', 'headline', 'bio', 'location', 'website'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FileOwner, HasLocalePreference, HasMedia, MustVerifyEmail
{
    public const ROLE_SUPER_ADMIN = 'SuperAdmin';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasFiles, HasRoles, Impersonate, InteractsWithMedia, LogsActivity, Notifiable, Searchable, TwoFactorAuthenticatable;

    public const USERS_LIST_CACHE_KEY = 'admin.users.index';

    public const USERS_LIST_VERSION_KEY = 'admin.users.index.version';

    protected static function booted(): void
    {
        // public_id is the only identifier exposed in the URL (see /u/{user:public_id}).
        // We allocate it before the row is inserted so newly-created users have a
        // routable handle from the very first save.
        static::creating(function (User $user): void {
            if (empty($user->public_id)) {
                $user->public_id = (string) Str::uuid();
            }
        });

        // Any CRUD event on a user row bumps the version counter baked into
        // the Admin Users index cache key, invalidating stale entries on the
        // next read without relying on tagged cache drivers.
        static::created(fn () => self::bumpUsersListVersion());
        static::updated(fn () => self::bumpUsersListVersion());
        static::deleted(fn () => self::bumpUsersListVersion());
        if (method_exists(static::class, 'restored')) {
            static::restored(fn () => self::bumpUsersListVersion());
        }
    }

    public static function usersListVersion(): int
    {
        // Redis stores incremented values as strings — coerce before returning
        // so the version key stays stable (both cache stores considered).
        $v = Cache::get(self::USERS_LIST_VERSION_KEY);

        return is_numeric($v) ? (int) $v : 1;
    }

    public static function bumpUsersListVersion(): void
    {
        // Seed before increment — Laravel's database/file stores require the
        // key to exist for increment() to apply. add() is a no-op when the
        // key already holds a value.
        Cache::add(self::USERS_LIST_VERSION_KEY, 1);
        Cache::increment(self::USERS_LIST_VERSION_KEY);
    }

    /**
     * User rows live in the central schema — pin so queries don't follow the
     * tenant schema swap performed by stancl/tenancy's DatabaseTenancyBootstrapper.
     */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function canImpersonate(): bool
    {
        return $this->isSuperAdmin();
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->isSuperAdmin();
    }

    /**
     * Platform super-user flag. Stored as a plain column on users rather than
     * a Spatie role: Spatie's team schema forces `model_has_roles.team_id` to
     * be non-null, so "global, not attached to any customer" isn't
     * representable there. Keeping it as a boolean also makes the distinction
     * crystal-clear — SuperAdmin is a platform property of the account, not a
     * per-customer assignment.
     */
    public function isSuperAdmin(): bool
    {
        // Read through `getAttribute` so the boolean cast declared in
        // casts() + any mutator layer applies consistently. Previously this
        // poked `$this->attributes` directly and bypassed the cast pipeline.
        return (bool) $this->getAttribute('is_super_admin');
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function ban(?string $reason = null): void
    {
        $this->forceFill([
            'banned_at' => now(),
            'banned_reason' => $reason,
        ])->save();
    }

    public function unban(): void
    {
        $this->forceFill([
            'banned_at' => null,
            'banned_reason' => null,
        ])->save();
    }

    public function scopeBanned($query)
    {
        return $query->whereNotNull('banned_at');
    }

    public function scopeNotBanned($query)
    {
        return $query->whereNull('banned_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 64, 64)
            ->format('webp')
            ->nonQueued()
            ->performOnCollections('avatar');

        $this->addMediaConversion('avatar')
            ->fit(Fit::Crop, 256, 256)
            ->format('webp')
            ->performOnCollections('avatar');
    }

    public function avatarUrl(string $conversion = 'avatar'): ?string
    {
        $media = $this->getFirstMedia('avatar');

        if (! $media) {
            return null;
        }

        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'email', 'email_verified_at', 'headline', 'bio', 'location', 'website'])
            ->logOnlyDirty()
            ->useLogName('user');
    }

    /**
     * @return array{
     *     id: int|string|null,
     *     first_name: string|null,
     *     last_name: string|null,
     * }
     */
    public function toSearchableArray(): array
    {
        // `email` is intentionally omitted — indexing it in external Scout
        // backends (Meilisearch, Algolia, etc.) leaks PII beyond what the
        // chat/user UI exposes, and every user-facing search UX in the app
        // filters by name only.
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
        ];
    }

    public function setting(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * Laravel reads this when sending notifications to switch app()->getLocale()
     * for the duration of rendering — makes `__()` and Blade `@lang` calls in
     * MailMessage / notifications respect the recipient's own language.
     */
    public function preferredLocale(): ?string
    {
        return $this->settings()->resolved()['locale'] ?? null;
    }

    /**
     * Conversations this user is a participant in.
     *
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * Count total unread messages across all conversations for this user.
     * Single aggregate query joining the conversation_user pivot.
     */
    public function unreadMessagesCount(): int
    {
        return Message::query()
            ->join('conversation_user', 'conversation_user.conversation_id', '=', 'messages.conversation_id')
            ->where('conversation_user.user_id', $this->id)
            // Deleted-sender rows (user_id IS NULL) still count as "not mine".
            ->where(function ($q): void {
                $q->where('messages.user_id', '!=', $this->id)
                    ->orWhereNull('messages.user_id');
            })
            ->where(function ($q): void {
                $q->whereNull('conversation_user.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_user.last_read_at');
            })
            ->count();
    }

    /**
     * Customers (a.k.a. tenants in package-speak) this user is a member of.
     * The underlying model is `App\Domains\Tenancy\Models\Tenant` because stancl/tenancy's base
     * contract names it that way; at the application layer we call them customers.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'workspace_user', 'user_id', 'workspace_id')
            ->withTimestamps();
    }

    public function belongsToCustomer(Tenant $customer): bool
    {
        return $this->customers()->whereKey($customer->getKey())->exists();
    }

    /**
     * Files where this user is the uploader/creator (regardless of who owns
     * the file). Distinct from files() which returns owned files via the
     * polymorphic owner relation. Mostly useful for "show me everything I
     * uploaded into the company tree".
     *
     * @return HasMany<FileItem, $this>
     */
    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(FileItem::class, 'user_id');
    }

    /**
     * A user owns a file iff they are its owner. Editors/Admins can manage
     * arbitrary user-owned files only via the cross-cutting `manage all
     * files` permission (or super-admin).
     */
    public function canManageFiles(User $user, ?Tenant $tenant = null): bool
    {
        if ($user->isSuperAdmin() || $user->can('manage all files')) {
            return true;
        }

        return $user->getKey() === $this->getKey();
    }

    public function canViewFiles(User $user, ?Tenant $tenant = null): bool
    {
        return $this->canManageFiles($user, $tenant);
    }

    /**
     * Get the user's settings, creating defaults if none exist yet.
     */
    public function settings(): UserSetting
    {
        // firstOrCreate is idempotent — safe to call multiple times per request
        $record = $this->setting()->firstOrCreate([], ['settings' => []]);

        // Keep the relationship cache in sync so subsequent ->setting accesses don't re-query
        $this->setRelation('setting', $record);

        return $record;
    }

    /**
     * Get the user's full name.
     */
    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Send the email verification notification using the MJML template.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the password reset notification using the MJML template.
     *
     * @param  string  $token
     *
     * Note: we can't add a `string` type hint here — the parent
     * `Illuminate\Foundation\Auth\User::sendPasswordResetNotification` has an
     * untyped parameter, and tightening the signature breaks LSP at runtime.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'banned_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'storage_used_bytes' => 'integer',
            'is_super_admin' => 'boolean',
            'platform_permissions' => 'array',
        ];
    }

    /**
     * Grantable platform-level capability (e.g. "manage_email_templates") —
     * not super-admin, not customer-scoped. Stored on the user row because
     * Spatie's team schema can't represent a team-less assignment. This is the
     * *explicit* grant only; SuperAdmins bypass abilities via Gate::before.
     */
    public function hasPlatformPermission(string $capability): bool
    {
        return in_array($capability, $this->platform_permissions ?? [], true);
    }
}
