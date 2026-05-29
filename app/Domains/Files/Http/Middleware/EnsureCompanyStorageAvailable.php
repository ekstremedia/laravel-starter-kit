<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Middleware;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Workspaces\Models\Workspace;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard company-files upload endpoints against exhausted tenant quotas.
 *
 * Mirrors EnsureStorageAvailable, but scopes to the tenant's company bucket
 * (workspaces.storage_quota_bytes) rather than a user's personal override.
 */
class EnsureCompanyStorageAvailable
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attributes->get('customer');
        if (! $workspace instanceof Workspace) {
            return $next($request);
        }

        $quota = $workspace->storage_quota_bytes;

        // 0 = hard disabled for company uploads. -1 / null = unlimited.
        if ($quota === 0) {
            $this->fail($request, 'files.company_quota_disabled');
        }

        if ($quota === null || (int) $quota < 0) {
            return $next($request);
        }

        $incoming = $this->incomingUploadBytes($request);
        $remaining = $this->usage->remainingBytesForTenantCompany($workspace) ?? PHP_INT_MAX;

        if ($incoming > $remaining) {
            $this->fail($request, 'files.company_quota_exceeded');
        }

        return $next($request);
    }

    private function fail(Request $request, string $key): never
    {
        $message = __($key);
        $fileField = $this->uploadFieldName($request);

        if ($request->hasHeader('X-Inertia') || $request->expectsJson() || $request->ajax()) {
            throw ValidationException::withMessages([$fileField => [$message]]);
        }

        throw new HttpResponseException(
            redirect()->back()->withErrors([$fileField => $message])->with('error', $message),
        );
    }

    private function uploadFieldName(Request $request): string
    {
        foreach (array_keys($request->allFiles()) as $key) {
            if ($key === 'files') {
                return 'files';
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
