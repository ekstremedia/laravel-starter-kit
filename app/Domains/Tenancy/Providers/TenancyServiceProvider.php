<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;

class TenancyServiceProvider extends ServiceProvider
{
    public static string $controllerNamespace = '';

    /**
     * @return array<class-string, array<int, mixed>>
     */
    public function events(): array
    {
        return [
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                ])->send(fn (Events\TenantCreated $event) => $event->tenant)
                    ->shouldBeQueued(false),
            ],

            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(fn (Events\TenantDeleted $event) => $event->tenant)
                    ->shouldBeQueued(false),
            ],

            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();

        // routes/tenant.php is mounted from bootstrap/app.php alongside web.php.
        // Tenant resolution middleware runs AFTER `auth` on purpose (membership check
        // needs Auth::user()), so we intentionally do not prepend it to the middleware
        // priority list.
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }
}
