<?php

use App\Domains\Operations\Jobs\PingJob;
use Illuminate\Support\Facades\Cache;

it('writes the nonce + timestamp to cache on handle', function () {
    Cache::forget('health:queue:last');

    $job = new PingJob('abc-123');
    $job->handle();

    $data = Cache::get('health:queue:last');

    expect($data)->toBeArray()
        ->and($data['nonce'])->toBe('abc-123')
        ->and($data['at'])->toBeString();
});
