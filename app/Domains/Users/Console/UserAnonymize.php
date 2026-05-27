<?php

namespace App\Domains\Users\Console;

use App\Domains\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Right-to-be-forgotten handler.
 *
 * We anonymize rather than delete because the user's id is referenced from
 * activity-log entries, authorship trails, and tenant membership tables where
 * a hard delete would break referential meaning. Scrubbing PII to an
 * irreversible token keeps history intact while making the user unreachable.
 *
 * If your compliance posture requires hard deletion of rows, wrap this
 * command with your own domain-specific cleanup (files, messages, orders,
 * …) first, then call `$user->delete()`.
 */
class UserAnonymize extends Command
{
    protected $signature = 'user:anonymize {email} {--force : Skip the confirmation prompt}';

    protected $description = 'Scrub personally-identifiable data from a user record while keeping the row for referential integrity.';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Anonymize {$user->email}? This cannot be undone.")) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $token = Str::lower(Str::random(16));

        // Wrap in a transaction so a mid-flight failure (DB hiccup, token
        // delete exception, activity log write, ...) rolls the user row back
        // to its pre-anonymization state — partial PII scrubs would be the
        // worst of both worlds.
        DB::transaction(function () use ($user, $token): void {
            $user->forceFill([
                'first_name' => 'Redacted',
                'last_name' => $token,
                'email' => "anonymized+{$token}@example.invalid",
                'password' => Hash::make(Str::random(40)),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'provider' => null,
                'provider_id' => null,
                'provider_avatar_url' => null,
                'banned_at' => now(),
                'banned_reason' => 'anonymized',
            ])->save();

            // Invalidate API tokens so the anonymized identity can't continue
            // to authenticate with a previously-issued Sanctum bearer token.
            $user->tokens()->delete();

            activity('user')->event('anonymized')->performedOn($user)->log('User record anonymized');
        });

        $this->info("Anonymized user {$user->id}. New placeholder email: {$user->email}");

        return self::SUCCESS;
    }
}
