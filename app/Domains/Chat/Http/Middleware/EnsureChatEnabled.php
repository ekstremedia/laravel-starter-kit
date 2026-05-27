<?php

namespace App\Domains\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('chat.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
