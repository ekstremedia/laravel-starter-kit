<?php

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Notifications\NotificationDigestNotification;
use App\Domains\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationDigest extends Command
{
    protected $signature = 'notifications:digest {--frequency=daily : Frequency to send (daily or weekly)}';

    protected $description = 'Send notification digest emails to users who opted in';

    public function handle(): int
    {
        $frequency = $this->option('frequency');

        if (! in_array($frequency, ['daily', 'weekly'])) {
            $this->error("Invalid frequency: {$frequency}. Use 'daily' or 'weekly'.");

            return self::FAILURE;
        }

        $users = User::notBanned()
            ->whereHas('setting', function ($q) use ($frequency) {
                $q->where('settings->notification_digest', $frequency);
            })
            ->get();

        $sent = 0;
        $failed = 0;
        $since = $frequency === 'daily' ? now()->subDay() : now()->subWeek();

        foreach ($users as $user) {
            $unread = $user->unreadNotifications()
                ->where('created_at', '>=', $since)
                ->get();

            if ($unread->isEmpty()) {
                continue;
            }

            try {
                // Queued: each send is independent, retryable, and doesn't block the
                // command loop on one slow SMTP connection.
                $user->notify(new NotificationDigestNotification($unread, $frequency));
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('Notification digest failed to queue', [
                    'user_id' => $user->id,
                    'frequency' => $frequency,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to queue digest for user {$user->id}: {$e->getMessage()}");
            }
        }

        $summary = "Queued {$sent} digest email(s) for frequency '{$frequency}'.";
        if ($failed > 0) {
            $summary .= " {$failed} failed.";
        }
        $this->info($summary);

        return self::SUCCESS;
    }
}
