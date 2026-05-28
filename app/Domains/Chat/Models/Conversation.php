<?php

namespace App\Domains\Chat\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $title
 * @property bool $is_group
 * @property int|null $created_by
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $users_count
 * @property-read User|null $creator
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $latestMessage
 * @property-read Pivot|null $pivot
 */
class Conversation extends Model
{
    public function getConnectionName(): ?string
    {
        return config('chat.connection', 'pgsql');
    }

    protected $fillable = ['title', 'is_group', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Scope to conversations where the given user is a participant.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('users', fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * Count messages the user hasn't read yet in this conversation.
     */
    public function unreadCountFor(User $user): int
    {
        /** @phpstan-ignore method.nonObject */
        $lastRead = $this->users()->where('user_id', $user->id)->first()?->pivot?->getAttribute('last_read_at');

        // "Not sent by me" must also match deleted-sender rows (user_id IS NULL),
        // otherwise orphaned messages silently drop out of unread counts.
        $query = $this->messages()->where(function (Builder $q) use ($user): void {
            $q->where('user_id', '!=', $user->id)->orWhereNull('user_id');
        });

        if ($lastRead) {
            $query->where('created_at', '>', $lastRead);
        }

        return $query->count();
    }

    /**
     * Check if a user is a participant in this conversation.
     */
    public function isParticipant(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Find an existing 1:1 conversation between two users, if any.
     */
    public static function findDirectBetween(int $userIdA, int $userIdB): ?self
    {
        // `having` on the aggregate subquery isn't portable to SQLite (CI/test
        // driver), so we eager-load the count and filter in memory. The two
        // whereHas clauses already narrow the candidate set to conversations
        // containing both users, so this is typically a 0–1 row result.
        return static::where('is_group', false)
            ->whereHas('users', fn (Builder $q) => $q->where('user_id', $userIdA))
            ->whereHas('users', fn (Builder $q) => $q->where('user_id', $userIdB))
            ->withCount('users')
            ->get()
            ->first(fn (self $c) => $c->users_count === 2);
    }
}
