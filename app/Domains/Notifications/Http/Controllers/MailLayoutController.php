<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Jobs\RecompileEmailTemplatesJob;
use App\Domains\Notifications\Models\MailLayout;
use App\Domains\Notifications\Services\MjmlCompiler;
use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Editing the email wrapper/branding (colours, header, footer, font). The
 * layout is global, so this is super-admin only (routes), and a save triggers
 * a recompile of every template since their cached HTML embeds the layout.
 */
class MailLayoutController extends Controller
{
    use BroadcastsResourceChanges;

    public function update(Request $request): RedirectResponse
    {
        $layout = MailLayout::current();
        $layout->fill($this->validated($request))->save();

        // Cached HTML embeds the layout — refresh every template off the queue.
        RecompileEmailTemplatesJob::dispatch();

        activity('mail_settings')
            ->performedOn($layout)
            ->event('layout_updated')
            ->log('Updated email layout');

        $this->broadcastResourceChanged('mail', 'updated', $layout->id);

        return back();
    }

    public function preview(Request $request): JsonResponse
    {
        // Render a representative email with the *draft* layout (unsaved edits).
        $draft = (new MailLayout)->forceFill(array_merge(
            MailLayout::current()->only((new MailLayout)->getFillable()),
            $this->validated($request),
        ));

        $mjml = View::make('mjml.layout', [
            'layout' => $draft,
            'heading' => __('Welcome, :name!', ['name' => 'John']),
            'body' => "This is a preview of your email layout.\n\nButtons, colours, header and footer all come from these settings.",
            'actionText' => __('Go to Dashboard'),
            'actionUrl' => config('app.url').'/app',
        ])->render();

        return response()->json([
            'html' => app(MjmlCompiler::class)->compile($mjml),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $hex = ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];

        return $request->validate([
            'brand_color' => $hex,
            'button_color' => $hex,
            'body_bg' => $hex,
            'card_bg' => $hex,
            'text_color' => $hex,
            'heading_color' => $hex,
            'footer_color' => $hex,
            'font_family' => ['required', 'string', 'max:255'],
            'header_mode' => ['required', 'in:text,image'],
            'header_logo_url' => ['nullable', 'url', 'starts_with:http://,https://', 'max:500'],
            'footer_text' => ['required', 'string', 'max:500'],
        ]);
    }
}
