<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    $this->customer = createCustomer();
    $this->avatarUrl = customerUrl($this->customer, '/profile/avatar');
});

it('generates the thumb conversion synchronously on upload', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->post($this->avatarUrl, [
            'avatar' => UploadedFile::fake()->image('a.png', 400, 400),
        ])
        ->assertRedirect();

    $media = $user->fresh()->getFirstMedia('avatar');

    expect($media)->not->toBeNull()
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue();
});

it('updates the avatar on re-upload', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->post($this->avatarUrl, ['avatar' => UploadedFile::fake()->image('first.png', 400, 400)])
        ->assertRedirect();

    $firstId = $user->fresh()->getFirstMedia('avatar')->id;

    $this->actingAs($user)
        ->post($this->avatarUrl, ['avatar' => UploadedFile::fake()->image('second.png', 400, 400)])
        ->assertRedirect();

    $latest = $user->fresh()->getMedia('avatar')->last();

    expect($latest->id)->not->toBe($firstId)
        ->and($latest->file_name)->toBe('second.png');
});

it('enforces the upload size ceiling', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->post($this->avatarUrl, [
            'avatar' => UploadedFile::fake()->image('big.png')->size(52000),
        ])
        ->assertSessionHasErrors('avatar');
});
