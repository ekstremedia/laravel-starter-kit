<?php

namespace App\Domains\Chat\Models;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_encrypted
 * @property string $type
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation $conversation
 * @property-read User|null $user
 */
class Message extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function getConnectionName(): ?string
    {
        return config('chat.connection', 'pgsql');
    }

    // `is_encrypted` intentionally omitted — the body mutator is the single
    // source of truth for encryption state. Allowing mass-assignment here
    // would let callers override the flag and desync the stored cipher.
    protected $fillable = ['conversation_id', 'user_id', 'body', 'type'];

    public function registerMediaCollections(): void
    {
        // Accept any user-uploaded file type; size limit is enforced by the
        // controller's validation rules. Restricting mime types at the model
        // level is too easy to outgrow — the UI's file picker is the gate.
        $this->addMediaCollection('attachments');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Only attempt the image thumb conversion for image uploads. Spatie
        // gracefully skips non-imageable files today, but making it explicit
        // prevents surprises if the whitelist broadens later.
        if ($media !== null && ! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        // Queued (not nonQueued): generate the thumbnail off the request thread.
        // A synchronous conversion that throws — e.g. Imagick choking on a
        // corrupt/undecodable upload — would otherwise 500 the send and lose
        // the message even though the original file stored fine. Queued, the
        // original is always saved and the message delivered; a failed
        // conversion just means no thumb (the UI falls back to the original
        // image). Requires a queue worker, which the kit ships with (Horizon).
        $this->addMediaConversion('thumb')
            ->fit(Fit::Contain, 320, 320)
            ->format('webp')
            ->queued()
            ->performOnCollections('attachments');
    }

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Transparently encrypt/decrypt the message body based on config and stored flag.
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): string {
                if ($value === null || $value === '') {
                    return '';
                }

                if ($this->is_encrypted) {
                    try {
                        return Crypt::decryptString($value);
                    } catch (\Throwable) {
                        return __('chat.decrypt_failed');
                    }
                }

                return $value;
            },
            set: function (?string $value): array {
                $value ??= '';

                if (config('chat.encryption_enabled')) {
                    return [
                        'body' => Crypt::encryptString($value),
                        'is_encrypted' => true,
                    ];
                }

                return [
                    'body' => $value,
                    'is_encrypted' => false,
                ];
            },
        );
    }
}
