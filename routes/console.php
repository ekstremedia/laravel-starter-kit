<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backups (spatie/laravel-backup)
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:monitor')->daily()->at('06:00');

// Activity log cleanup. (Pulse trims its own data automatically during ingest
// per config('pulse.storage.trim.keep') — there is no `pulse:trim` command.)
Schedule::command('activitylog:clean')->daily();

// Notification digests
Schedule::command('notifications:digest --frequency=daily')->dailyAt('08:00');
Schedule::command('notifications:digest --frequency=weekly')->weeklyOn(1, '08:00');

// Per-user storage usage snapshot (feeds admin growth-over-time chart and
// refreshes the denormalized users.storage_used_bytes column as a backstop).
Schedule::command('app:snapshot-storage-usage')->dailyAt('03:15');

// Permanently delete file items that have been in the trash for 30+ days.
Schedule::command('app:purge-trashed-file-items')->dailyAt('03:45');
