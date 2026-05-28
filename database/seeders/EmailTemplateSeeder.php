<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Template definitions live in config/mail-templates.php (the registry).
 * Seeding just runs the sync command so `db:seed` and the registry never
 * drift. Existing admin-edited copy is preserved (the command only seeds
 * content on first creation).
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('mail:sync-templates');
    }
}
