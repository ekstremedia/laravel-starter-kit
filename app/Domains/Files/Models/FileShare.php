<?php

declare(strict_types=1);

namespace App\Domains\Files\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token
 * @property int $file_item_id
 * @property int|null $created_by
 * @property Carbon $expires_at
 * @property string|null $password_hash
 * @property int $view_count
 * @property Carbon|null $last_viewed_at
 * @property-read FileItem $fileItem
 */
class FileShare extends Model
{
    protected $fillable = ['token', 'file_item_id', 'created_by', 'expires_at', 'password_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function fileItem(): BelongsTo
    {
        return $this->belongsTo(FileItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        // expires_at is NOT NULL at the DB level; guarding is redundant today
        // but keeps the call safe if the schema ever relaxes.
        return $this->expires_at->isPast();
    }

    public function requiresPassword(): bool
    {
        return $this->password_hash !== null;
    }
}
