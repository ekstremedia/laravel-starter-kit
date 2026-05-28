<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompiles every email template's cached HTML. Dispatched after the shared
 * layout/branding changes, since each template's compiled_html embeds the
 * layout. Queued because MJML compilation shells out per template.
 */
class RecompileEmailTemplatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        EmailTemplate::query()->each(function (EmailTemplate $template): void {
            $template->compile();
        });
    }
}
