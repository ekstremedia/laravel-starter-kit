<?php

namespace App\Domains\Operations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class PingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $nonce) {}

    public function handle(): void
    {
        Cache::put('health:queue:last', [
            'nonce' => $this->nonce,
            'at' => now()->toIso8601String(),
        ], now()->addHour());
    }
}
