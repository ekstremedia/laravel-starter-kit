<?php

use App\Domains\Access\Http\Middleware\EnsureSuperAdmin;
use App\Domains\Access\Http\Middleware\EnsureWorkspaceAdmin;
use App\Domains\Chat\Http\Middleware\EnsureChatEnabled;
use App\Domains\Files\Http\Middleware\EnsureCompanyStorageAvailable;
use App\Domains\Files\Http\Middleware\EnsureStorageAvailable;
use App\Domains\Modules\Http\Middleware\EnsureModuleEnabled;
use App\Domains\Settings\Http\Middleware\EnforceAppSettings;
use App\Domains\Workspaces\Http\Middleware\BindDefaultWorkspace;
use App\Domains\Workspaces\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Workspace routes. Multi-tenant: mounted under /w/{workspace} and
            // resolved by slug. Single-tenant (tenancy disabled): mounted at the
            // root with no prefix and bound to the one default workspace — so
            // the app reads like a normal Laravel app. Paths that also exist
            // centrally (/profile, /notifications in web.php, registered first)
            // win the match; the workspace duplicates are harmless shadows.
            if (config('workspaces.enabled')) {
                Route::prefix('w/{workspace}')
                    ->middleware(['web', 'auth', 'verified', ResolveWorkspace::class])
                    ->name('workspace.')
                    ->group(__DIR__.'/../routes/workspace.php');
            } else {
                Route::middleware(['web', 'auth', 'verified', BindDefaultWorkspace::class])
                    ->name('workspace.')
                    ->group(__DIR__.'/../routes/workspace.php');
            }
        },
    )
    // Auto-discover Artisan commands living inside domain modules
    // (app/Domains/*/Console). The framework only scans app/Console/Commands
    // by default; this recursively registers any Command subclass under a
    // domain so commands move with their domain.
    ->withCommands([__DIR__.'/../app/Domains'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs before route matching so 404s / redirects also get the headers
        // and correlation id.
        $middleware->prepend([
            RequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            SetLocaleFromUser::class,
            HandleInertiaRequests::class,
            EnsureUserIsNotBanned::class,
            EnforceAppSettings::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'chat.enabled' => EnsureChatEnabled::class,
            'storage.available' => EnsureStorageAvailable::class,
            'company.storage.available' => EnsureCompanyStorageAvailable::class,
            'workspace.admin' => EnsureWorkspaceAdmin::class,
            'super.admin' => EnsureSuperAdmin::class,
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // Render a token-styled Inertia error page for the common HTTP
        // error codes outside local/testing (so stack traces still surface
        // during development). 419 falls back to the standard "page expired"
        // redirect so form resubmits keep their old behavior.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();
            $isLocalOrTesting = app()->environment(['local', 'testing']);

            if ($status === 419) {
                return back()->with('flash', [
                    'level' => 'warning',
                    'message' => __('flash.session.expired'),
                ]);
            }

            // 403 / 404 always get the friendly Inertia page — there's no
            // stack trace to inspect, and a plain Symfony error page is a
            // worse developer experience than a styled in-app 404. For 500
            // / 503 in local/testing we let Ignition render the stack trace.
            $inertiaSafeStatuses = $isLocalOrTesting ? [403, 404] : [403, 404, 500, 503];

            if (in_array($status, $inertiaSafeStatuses, true)) {
                // Only forward the exception message for client-safe statuses.
                // For 500/503 we suppress it — a raw message on the error page
                // can leak connection strings, file paths, or stack detail
                // from the underlying exception. The Vue page falls back to
                // the localized generic description when message is empty.
                $clientSafeStatus = in_array($status, [403, 404], true);
                $message = ($clientSafeStatus && $exception instanceof HttpExceptionInterface)
                    ? $exception->getMessage()
                    : '';

                return Inertia::render('Errors/Error', [
                    'status' => $status,
                    'message' => $message,
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
