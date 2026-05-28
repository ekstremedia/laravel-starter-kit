<?php

namespace App\Domains\Notifications\Providers;

use App\Domains\Notifications\Models\MailSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MailSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            // Skip during console commands (e.g. migrations) to avoid chicken-and-egg issues.
            return;
        }

        try {
            if (! Schema::hasTable('mail_settings')) {
                return;
            }

            MailSetting::query()->first()?->applyToConfig();
        } catch (Throwable) {
            // Swallow bootstrap errors; fall back to .env config.
        }
    }
}
