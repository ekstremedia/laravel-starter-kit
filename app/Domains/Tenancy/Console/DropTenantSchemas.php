<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('db:drop-tenant-schemas {--force : Allow running outside local}')]
#[Description('Drop every tenant<id> Postgres schema left behind by stancl/tenancy. Used by `make rebuild` to reset the DB to a clean slate.')]
class DropTenantSchemas extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local', 'testing') && ! $this->option('force')) {
            $this->error('Refusing to drop tenant schemas outside local/testing (APP_ENV='.app()->environment().'). Pass --force to override.');

            return self::FAILURE;
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->warn('Nothing to drop: tenant schemas are a Postgres-only concern (current driver: '.$connection->getDriverName().').');

            return self::SUCCESS;
        }

        // Match only the exact naming scheme stancl/tenancy produces
        // (`tenant<id>` where id is numeric) — don't touch unrelated schemas
        // like `tenant_audit` that a future migration might introduce.
        $schemas = $connection->select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name ~ '^tenant[0-9]+$'"
        );

        if ($schemas === []) {
            $this->info('No tenant schemas to drop.');

            return self::SUCCESS;
        }

        foreach ($schemas as $row) {
            $name = $row->schema_name;
            $connection->statement('DROP SCHEMA "'.$name.'" CASCADE');
            $this->line(" dropped <fg=yellow>{$name}</>");
        }

        $this->info(count($schemas).' schema(s) dropped.');

        return self::SUCCESS;
    }
}
