<?php

use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('builds a full name from first and last', function () {
    $u = new User(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($u->fullName())->toBe('Ada Lovelace');
});

it('returns null avatarUrl when no media present', function () {
    $user = User::factory()->create();

    expect($user->avatarUrl())->toBeNull();
});

it('returns an avatarUrl string after uploading media', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $user->addMedia(UploadedFile::fake()->image('a.png', 400, 400))
        ->toMediaCollection('avatar');

    expect($user->fresh()->avatarUrl())->toBeString()
        ->and($user->fresh()->avatarUrl('thumb'))->toBeString();
});

it('configures activity log to only include whitelisted fields', function () {
    $options = (new User)->getActivitylogOptions();

    expect($options->logAttributes)->toEqualCanonicalizing(
        ['first_name', 'last_name', 'email', 'email_verified_at', 'headline', 'bio', 'location', 'website']
    )
        ->and($options->logName)->toBe('user')
        ->and($options->logOnlyDirty)->toBeTrue();
});
