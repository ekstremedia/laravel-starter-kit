<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Users\Models\User;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invitation for someone (by email) to join a workspace with a given
 * role. The invitee may not have an account yet — they accept via a tokenised
 * link, registering or logging in along the way.
 *
 * Uses BelongsToTenant so listing/creating invitations inside a workspace is
 * auto-scoped; the public accept route runs without a workspace context (scope
 * inert), so it can still resolve an invitation by its unique token.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property string $role
 * @property string $token
 * @property int|null $invited_by_user_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Tenant $tenant
 * @property-read User|null $invitedBy
 */
class WorkspaceInvitation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'email', 'role', 'token', 'invited_by_user_id', 'expires_at', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'workspace_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public static function freshToken(): string
    {
        return Str::random(48);
    }
}
