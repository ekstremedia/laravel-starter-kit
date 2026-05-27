<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Operations\Events\PingEvent;
use App\Domains\Operations\Jobs\PingJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class HealthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Health', [
            'queue' => [
                'last' => Cache::get('health:queue:last'),
                'driver' => config('queue.default'),
            ],
            'broadcast' => [
                'driver' => config('broadcasting.default'),
                'reverb_host' => config('broadcasting.connections.reverb.options.host'),
                'reverb_port' => config('broadcasting.connections.reverb.options.port'),
            ],
            'redis' => $this->redisStatus(),
        ]);
    }

    public function dispatchPing(): RedirectResponse
    {
        $nonce = (string) Str::uuid();
        PingJob::dispatch($nonce);

        return back()->with('success', __('flash.health.queue_ping', ['nonce' => $nonce]));
    }

    public function broadcastPing(): RedirectResponse
    {
        $nonce = (string) Str::uuid();
        event(new PingEvent($nonce, now()->toIso8601String()));

        return back()->with('success', __('flash.health.broadcast_ping', ['nonce' => $nonce]));
    }

    public function queueLast(): JsonResponse
    {
        return response()->json([
            'last' => Cache::get('health:queue:last'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function redisStatus(): array
    {
        try {
            $pong = Redis::connection()->ping();

            return ['ok' => true, 'pong' => is_bool($pong) ? ($pong ? 'PONG' : 'false') : (string) $pong];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
