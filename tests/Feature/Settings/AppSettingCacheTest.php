<?php

declare(strict_types=1);

use App\Domains\Settings\Models\AppSetting;

it('returns a usable, existing model when read from cache', function () {
    $first = AppSetting::current();
    expect($first)->toBeInstanceOf(AppSetting::class)
        ->and($first->exists)->toBeTrue();

    // Second call is served from cache and rehydrated — must still be a real,
    // existing model (not an incomplete/detached one).
    $second = AppSetting::current();
    expect($second)->toBeInstanceOf(AppSetting::class)
        ->and($second->id)->toBe($first->id)
        ->and($second->exists)->toBeTrue();
});

it('persists updates through the cached model without creating a duplicate row', function () {
    AppSetting::current(); // warm the cache

    AppSetting::current()->update(['registration_open' => false]);

    // The cache was busted on save, so the next read reflects the change…
    expect(AppSetting::current()->registration_open)->toBeFalse();
    // …and we updated the singleton row rather than inserting a second one
    // (which would happen if the rehydrated model wasn't marked as existing).
    expect(AppSetting::query()->count())->toBe(1);
});
