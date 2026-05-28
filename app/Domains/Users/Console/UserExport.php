<?php

namespace App\Domains\Users\Console;

use App\Domains\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Dump everything we hold for one user as JSON.
 *
 * Intended for GDPR / CCPA "right to access" requests. The scope is
 * intentionally conservative: profile fields, roles, permissions, settings,
 * activity-log entries, and notifications. Extend `payload()` with
 * domain-specific relations (files, orders, ...) as your app grows.
 */
class UserExport extends Command
{
    protected $signature = 'user:export {email} {--out= : Write to this file instead of stdout}';

    protected $description = 'Export every piece of user-identifiable data we store for the given account.';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        // JSON_THROW_ON_ERROR turns malformed UTF-8 / invalid floats etc. into
        // a real exception instead of silently writing an empty file.
        $json = json_encode(
            $this->payload($user),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $out = (string) ($this->option('out') ?? '');
        if ($out !== '') {
            // GDPR exports are pure PII. Lock the file, verify the write, and
            // clamp permissions to owner-only so a world-readable dump doesn't
            // end up on a shared host.
            $written = @file_put_contents($out, $json, LOCK_EX);
            if ($written === false) {
                $this->error("Failed to write export to {$out}.");

                return self::FAILURE;
            }
            if (! @chmod($out, 0o600)) {
                @unlink($out);
                $this->error("Failed to set 0600 permissions on {$out}; export aborted.");

                return self::FAILURE;
            }
            $this->info("Wrote export for {$user->email} to {$out}.");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'is_super_admin' => $user->isSuperAdmin(),
            // Console has no team context, so a naive `roles()->pluck('name')`
            // would come back empty. Build the full per-customer map directly
            // from `model_has_roles` so the GDPR export is accurate.
            'customer_roles' => $this->customerRoles($user),
            'settings' => $user->settings()->resolved(),
            'activity_as_causer' => Activity::query()
                ->where('causer_type', $user->getMorphClass())
                ->where('causer_id', $user->id)
                ->get(['id', 'log_name', 'event', 'description', 'created_at'])
                ->toArray(),
            'notifications' => $user->notifications()->get(['id', 'type', 'data', 'read_at', 'created_at'])->toArray(),
        ];
    }

    /**
     * @return array<int, array{customer_id:int, customer_slug:string|null, roles:array<int,string>}>
     */
    private function customerRoles(User $user): array
    {
        $mhr = config('permission.table_names.model_has_roles');
        $rolesTable = config('permission.table_names.roles');
        $teamKey = config('permission.column_names.team_foreign_key');

        // Console has no implicit connection context — pin to central since
        // `model_has_roles`, `roles`, and `tenants` all live on the landlord
        // schema and the default connection can be anything in a worker that
        // previously handled a tenant job.
        $central = (string) config('tenancy.database.central_connection');
        $rows = DB::connection($central)->table($mhr)
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$mhr}.role_id")
            ->leftJoin('tenants', 'tenants.id', '=', "{$mhr}.{$teamKey}")
            ->where("{$mhr}.model_type", (new User)->getMorphClass())
            ->where("{$mhr}.model_id", $user->id)
            // Defence against a future assignment that lands with a null
            // `team_id` — we'd otherwise group it under `customer_id: 0`
            // (the int-cast of null) and the PHPDoc `customer_id:int`
            // would lie to callers.
            ->whereNotNull("{$mhr}.{$teamKey}")
            ->get([
                "{$mhr}.{$teamKey} as customer_id",
                'tenants.slug as customer_slug',
                "{$rolesTable}.name as role",
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->customer_id] ??= [
                'customer_id' => (int) $row->customer_id,
                'customer_slug' => $row->customer_slug,
                'roles' => [],
            ];
            $grouped[$row->customer_id]['roles'][] = $row->role;
        }

        return array_values($grouped);
    }
}
