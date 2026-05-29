<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Middleware;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\OwnerResolver;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard upload endpoints against users who have no quota left.
 *
 * Usage: attach to POST routes that create media (file upload, chat
 * attachment). The check uses the incoming request's uploaded-file sizes so
 * we reject the request *before* writing to disk.
 */
class EnsureStorageAvailable
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Quota is applied per-workspace. On workspace-scoped routes
        // `ResolveWorkspace` stashes the workspace in request attributes.
        // If this middleware is ever hit outside a workspace route (shouldn't
        // happen in practice), skip — there's nothing to scope against.
        $workspace = $request->attributes->get('workspace');
        if (! $workspace instanceof Workspace) {
            return $next($request);
        }

        // Quota applies to whichever owner the upload targets: the user for
        // personal files, or an entity (Asset, …) for entity files. Falls
        // back to the user when no owner_type/owner_id is on the request.
        $owner = OwnerResolver::fromRequest($request, $user);
        $quota = $this->usage->effectiveQuota($owner, $workspace);

        // 0 = hard disabled.
        if ($quota === 0) {
            $this->fail($request, 'files.quota_disabled');
        }

        // null = unlimited. Nothing to check.
        if ($quota === null) {
            return $next($request);
        }

        $incoming = $this->incomingUploadBytes($request);
        $remaining = $this->usage->remainingBytesForOwner($owner, $workspace) ?? PHP_INT_MAX;

        if ($incoming > $remaining) {
            $this->fail($request, 'files.quota_exceeded');
        }

        return $next($request);
    }

    /**
     * Translate quota rejections into:
     *   - a ValidationException for Inertia / AJAX requests (dialogs render
     *     the error inline without the ugly Whoops error page), and
     *   - a back-redirect with a flash toast for regular form submits.
     */
    private function fail(Request $request, string $key): never
    {
        $message = __($key);
        $fileField = $this->uploadFieldName($request);

        if ($request->hasHeader('X-Inertia') || $request->expectsJson() || $request->ajax()) {
            throw ValidationException::withMessages([$fileField => [$message]]);
        }

        // Non-AJAX form posts get a back-redirect with the flash error. Throw
        // HttpResponseException so Laravel short-circuits the pipeline rather
        // than using `abort(redirect())`, which isn't an idiomatic abort() call.
        throw new HttpResponseException(
            redirect()->back()->withErrors([$fileField => $message])->with('error', $message),
        );
    }

    private function uploadFieldName(Request $request): string
    {
        // Pick whichever file key the endpoint used — we guard both the file
        // manager (`files`) and chat (`attachments`). Fall back to `files`.
        foreach (array_keys($request->allFiles()) as $key) {
            if (in_array($key, ['files', 'attachments'], true)) {
                return $key;
            }
        }

        return 'files';
    }

    private function incomingUploadBytes(Request $request): int
    {
        $total = 0;
        foreach ($request->allFiles() as $files) {
            foreach (is_array($files) ? $files : [$files] as $file) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }
}
