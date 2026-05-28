<?php

declare(strict_types=1);

namespace App\Domains\Files\Console;

use App\Domains\Files\Models\FileItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:purge-trashed-file-items {--days=30 : Retention window in days}')]
#[Description('Permanently delete file items that have been in the trash past the retention window.')]
class PurgeTrashedFileItems extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = 0;
        FileItem::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->chunkById(100, function ($items) use (&$count): void {
                foreach ($items as $item) {
                    $item->forceDelete();
                    $count++;
                }
            });

        $this->info("Purged {$count} trashed file item(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
