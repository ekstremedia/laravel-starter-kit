<?php

declare(strict_types=1);

namespace App\Domains\Files\Console;

use App\Domains\Chat\Models\Message;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Users\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:snapshot-storage-usage')]
#[Description('Record a daily per-user storage-usage snapshot and refresh the denormalized users.storage_used_bytes column.')]
class SnapshotStorageUsage extends Command
{
    public function handle(StorageUsageService $service): int
    {
        $today = now()->toDateString();
        $count = 0;
        $conn = (string) config('tenancy.database.central_connection');

        // `User::on($conn)` keeps the chunk query on the central connection
        // even if something upstream swapped the default (tenancy bootstrap).
        User::on($conn)->chunkById(200, function ($users) use ($service, $today, $conn, &$count): void {
            foreach ($users as $user) {
                $bytes = $service->recomputeForUser($user);
                $fileCount = $this->fileCountFor($user->id);

                $key = ['user_id' => $user->id, 'workspace_id' => null, 'snapshot_date' => $today];
                $now = now();

                // Distinguish insert vs update so we don't overwrite the
                // original `created_at` on re-runs (updateOrInsert sets every
                // column on both paths).
                $exists = DB::connection($conn)->table('storage_snapshots')
                    ->where($key)->exists();

                $payload = ['bytes_used' => $bytes, 'file_count' => $fileCount, 'updated_at' => $now];

                if ($exists) {
                    DB::connection($conn)->table('storage_snapshots')
                        ->where($key)->update($payload);
                } else {
                    DB::connection($conn)->table('storage_snapshots')->insert(
                        array_merge($key, $payload, ['created_at' => $now]),
                    );
                }

                $count++;
            }
        });

        $this->info("Snapshotted {$count} users.");

        return self::SUCCESS;
    }

    private function fileCountFor(int $userId): int
    {
        $conn = (string) config('tenancy.database.central_connection');

        return (int) DB::connection($conn)->table('media')
            ->leftJoin('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->leftJoin('messages', function ($join): void {
                $join->on('messages.id', '=', 'media.model_id')
                    ->where('media.model_type', (new Message)->getMorphClass());
            })
            ->where(function ($q) use ($userId): void {
                $q->where('file_items.user_id', $userId)
                    ->orWhere('messages.user_id', $userId)
                    ->orWhere(function ($q2) use ($userId): void {
                        $q2->where('media.model_type', (new User)->getMorphClass())
                            ->where('media.model_id', $userId);
                    });
            })
            ->count('media.id');
    }
}
