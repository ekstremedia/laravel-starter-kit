<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SystemInfoController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/System', [
            'php' => $this->php(),
            'system' => $this->system(),
            'laravel' => $this->laravel(),
            'drivers' => $this->drivers(),
            'cache_status' => $this->cacheStatus(),
            'extensions' => get_loaded_extensions(),
            'health' => [
                'queue' => [
                    'last' => Cache::get('health:queue:last'),
                    'driver' => config('queue.default'),
                ],
                'broadcast' => [
                    'driver' => config('broadcasting.default'),
                ],
                'redis' => $this->redisStatus(),
            ],
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

    /**
     * @return array<string, string>
     */
    private function php(): array
    {
        return [
            'version' => PHP_VERSION,
            'zend_version' => zend_version(),
            'sapi' => PHP_SAPI,
            'ini_loaded_file' => (string) php_ini_loaded_file(),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'max_file_uploads' => (string) ini_get('max_file_uploads'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function system(): array
    {
        return [
            'os' => PHP_OS,
            'os_family' => PHP_OS_FAMILY,
            'hostname' => (string) gethostname(),
            'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'cli'),
            'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? base_path('public')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function laravel(): array
    {
        $composerVersion = 'unknown';
        try {
            $composerVersion = InstalledVersions::getVersion('composer/composer') ?? 'unknown';
        } catch (Throwable) {
            // leave unknown
        }

        return [
            'app_name' => config('app.name'),
            'version' => App::version(),
            'composer_version' => $composerVersion,
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'url' => config('app.url'),
            'maintenance' => app()->isDownForMaintenance(),
            'timezone' => config('app.timezone'),
            'locale' => app()->getLocale(),
            'storage_linked' => File::exists(public_path('storage')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function drivers(): array
    {
        return [
            'broadcast' => (string) config('broadcasting.default'),
            'cache' => (string) config('cache.default'),
            'database' => (string) config('database.default'),
            'logs' => (string) config('logging.default'),
            'mail' => (string) config('mail.default'),
            'queue' => (string) config('queue.default'),
            'session' => (string) config('session.driver'),
            'filesystem' => (string) config('filesystems.default'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function cacheStatus(): array
    {
        $bootstrap = base_path('bootstrap/cache');

        return [
            'config' => File::exists($bootstrap.'/config.php'),
            'routes' => File::exists($bootstrap.'/routes-v7.php') || File::exists($bootstrap.'/routes.php'),
            'events' => File::exists($bootstrap.'/events.php'),
            'views' => count(File::files(storage_path('framework/views'))) > 0,
        ];
    }
}
