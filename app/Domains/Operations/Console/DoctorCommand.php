<?php

namespace App\Domains\Operations\Console;

use App\Domains\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Preflight check for a fresh clone of the kit.
 *
 * Runs a battery of cheap probes and prints a PASS/WARN/FAIL report. The
 * exit status is non-zero when any required check fails, so this is
 * CI-friendly as well as developer-friendly.
 */
class DoctorCommand extends Command
{
    protected $signature = 'starter:doctor {--json : Emit the report as JSON}';

    protected $description = 'Validate that the app can actually boot, talk to its services, and serve requests.';

    /** @var list<array{name: string, status: string, detail: string, required: bool}> */
    private array $results = [];

    public function handle(): int
    {
        $this->check('APP_KEY is set', fn () => (string) config('app.key') !== '', 'Run `php artisan key:generate`.');
        $this->check('APP_URL looks sane', fn () => filter_var(config('app.url'), FILTER_VALIDATE_URL) !== false, 'APP_URL in .env is not a valid URL.');
        $this->check('Database reachable', fn () => $this->pingDatabase(), 'Check DB_* env vars and that the database is running.');
        $this->check('Migrations are applied', fn () => Schema::hasTable('users'), 'Run `php artisan migrate`.');
        $this->check('Redis reachable', fn () => $this->pingRedis(), 'Check REDIS_HOST / REDIS_PORT; Horizon and chat need it.', required: false);
        $this->check('Mail transport configured', fn () => (string) config('mail.default') !== '', 'Set MAIL_MAILER / MAIL_HOST.', required: false);
        $this->check('Storage disk writable', fn () => $this->storageWritable(), 'storage/app must be writable by the PHP process.');
        $this->check('Admin user seeded', fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->exists(), 'Run `php artisan db:seed` or create an admin via `php artisan user:create`.', required: false);
        $this->check('Queue driver set', fn () => (string) config('queue.default') !== 'sync' || app()->runningUnitTests(), 'Use `redis`/`database` for real deployments; `sync` blocks web responses.', required: false);
        $this->check('Telescope / debug off in production', fn () => ! app()->isProduction() || ! config('app.debug'), 'APP_DEBUG must be false in production.');

        if ($this->option('json')) {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable();
        }

        $failed = collect($this->results)->contains(fn ($r) => $r['required'] && $r['status'] === 'FAIL');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $name, callable $probe, string $hint = '', bool $required = true): void
    {
        try {
            $ok = (bool) $probe();
            $this->results[] = [
                'name' => $name,
                'status' => $ok ? 'PASS' : ($required ? 'FAIL' : 'WARN'),
                'detail' => $ok ? 'ok' : $hint,
                'required' => $required,
            ];
        } catch (\Throwable $e) {
            $this->results[] = [
                'name' => $name,
                'status' => $required ? 'FAIL' : 'WARN',
                'detail' => trim($e->getMessage()) ?: $hint,
                'required' => $required,
            ];
        }
    }

    private function renderTable(): void
    {
        $rows = array_map(fn ($r) => [
            match ($r['status']) {
                'PASS' => '<fg=green>PASS</>',
                'WARN' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            },
            $r['name'],
            $r['detail'],
        ], $this->results);

        $this->table(['', 'Check', 'Detail'], $rows);
    }

    private function pingDatabase(): bool
    {
        DB::connection()->getPdo();

        return true;
    }

    private function pingRedis(): bool
    {
        Redis::connection()->ping();

        return true;
    }

    private function storageWritable(): bool
    {
        $probe = 'doctor-probe-'.uniqid().'.txt';
        Storage::put($probe, 'ok');
        $exists = Storage::exists($probe);
        Storage::delete($probe);

        return $exists;
    }
}
