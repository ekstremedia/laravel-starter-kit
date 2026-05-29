<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Files\Models\FileItem;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<FileItem>
 */
class FileItemFactory extends Factory
{
    /** @var class-string<FileItem> */
    protected $model = FileItem::class;

    /**
     * Default: a User-owned (personal-scope) file. The creator and the owner
     * are the same User by convention; tests that need divergent
     * "uploaded by X, owned by Y" data should set them explicitly via
     * ->ownedBy($model).
     *
     * @return array{workspace_id: mixed, user_id: mixed, parent_id: int|null, type: string, scope: string, name: string, mime_type: string, size: int}
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'type' => FileItem::TYPE_FILE,
            'scope' => FileItem::SCOPE_PERSONAL,
            'name' => fake()->word().'-'.Str::random(6).'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(1_000, 5_000_000),
        ];
    }

    /**
     * Default the polymorphic owner to the creating user when the test
     * didn't set it explicitly. Letting the test's `user_id => $this->user`
     * override flow naturally into ownership keeps existing tests working
     * without per-call ->ownedBy() boilerplate.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (FileItem $item): void {
            $attrs = $item->getAttributes();
            if (! isset($attrs['owner_type']) && isset($attrs['user_id'])) {
                $item->owner_type = (new User)->getMorphClass();
                $item->owner_id = (int) $attrs['user_id'];
            }
        })->afterCreating(function (FileItem $item): void {
            $attrs = $item->getAttributes();
            if (! isset($attrs['owner_type']) && isset($attrs['user_id'])) {
                $item->forceFill([
                    'owner_type' => (new User)->getMorphClass(),
                    'owner_id' => (int) $attrs['user_id'],
                ])->saveQuietly();
            }
        });
    }

    public function folder(): static
    {
        return $this->state(fn () => [
            'type' => FileItem::TYPE_FOLDER,
            'mime_type' => null,
            'size' => 0,
            'name' => fake()->word().'-'.Str::random(6),
        ]);
    }

    /**
     * Make this FileItem owned by the given model (User personal, Tenant
     * company, or any other FileOwner).
     */
    public function ownedBy(Model $owner): static
    {
        return $this->state(fn () => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'scope' => $owner instanceof Tenant ? FileItem::SCOPE_COMPANY : FileItem::SCOPE_PERSONAL,
        ]);
    }

    public function company(Tenant $tenant): static
    {
        return $this->state(fn () => [
            'workspace_id' => $tenant->getKey(),
            'owner_type' => $tenant->getMorphClass(),
            'owner_id' => $tenant->getKey(),
            'scope' => FileItem::SCOPE_COMPANY,
        ]);
    }
}
