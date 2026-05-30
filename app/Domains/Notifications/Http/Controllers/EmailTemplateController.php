<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Mail\TemplateMail;
use App\Domains\Notifications\Models\EmailTemplate;
use App\Domains\Notifications\Services\MjmlCompiler;
use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

class EmailTemplateController extends Controller
{
    use BroadcastsResourceChanges;

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $data = $request->validate($this->contentRules());

        $template->update($data);
        $template->compile();

        $this->broadcastResourceChanged('mail', 'updated', $template->id);

        return back();
    }

    public function preview(Request $request, EmailTemplate $template): JsonResponse
    {
        $draft = $this->resolveDraft($request, $template);
        $sampleData = $this->sampleData($template);

        return response()->json([
            'html' => $this->renderDraft($draft, $sampleData),
        ]);
    }

    public function testSend(Request $request, EmailTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $draft = $this->resolveDraft($request, $template);
        $sampleData = $this->sampleData($template);
        $subject = $this->interpolate($draft['subject'], $sampleData);
        $html = $this->renderDraft($draft, $sampleData);

        Mail::to($data['email'])->send(new TemplateMail($subject, $html));

        return back()->with('success', __('flash.email_templates.test_sent', ['email' => $data['email']]));
    }

    /**
     * Build the working draft: use request fields when present (unsaved edits),
     * otherwise fall back to the persisted template.
     *
     * @return array{subject: string, heading: string, body: string, action_text: string|null, action_url: string|null}
     */
    private function resolveDraft(Request $request, EmailTemplate $template): array
    {
        return [
            'subject' => $request->string('subject')->toString() ?: $template->subject,
            'heading' => $request->string('heading')->toString() ?: ($template->heading ?? ''),
            'body' => $request->string('body')->toString() ?: $template->body,
            'action_text' => $request->string('action_text')->toString() ?: $template->action_text,
            'action_url' => $request->string('action_url')->toString() ?: $template->action_url,
        ];
    }

    /**
     * Compile a draft through the MJML layout and interpolate sample data.
     *
     * @param  array{subject: string, heading: string, body: string, action_text: string|null, action_url: string|null}  $draft
     * @param  array<string, string>  $data
     */
    private function renderDraft(array $draft, array $data): string
    {
        $mjml = View::make('mjml.layout', [
            'heading' => $draft['heading'],
            'body' => $draft['body'],
            'actionText' => $draft['action_text'],
            'actionUrl' => $draft['action_url'],
        ])->render();

        $compiler = app(MjmlCompiler::class);
        $html = $compiler->compile($mjml);

        foreach ($data as $key => $value) {
            $html = str_replace('{{ '.$key.' }}', e($value), $html);
        }

        return $html;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function interpolate(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $text = str_replace('{{ '.$key.' }}', $value, $text);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function contentRules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'action_text' => ['nullable', 'string', 'max:255'],
            'action_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $value = trim($value);
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    $scheme = is_string($scheme) ? strtolower($scheme) : null;
                    $isHttpUrl = filter_var($value, FILTER_VALIDATE_URL) !== false
                        && in_array($scheme, ['http', 'https'], true);

                    // Placeholders are OK only at the start of the value — a
                    // prefixed unsafe scheme like "javascript:{{ app_url }}"
                    // must be rejected even though it contains `{{`.
                    $startsWithPlaceholder = preg_match('/^\s*\{\{\s*[^}]+\s*\}\}/', $value) === 1;

                    if (! $isHttpUrl && ! $startsWithPlaceholder) {
                        $fail('The action URL must be an http(s) URL or start with a template placeholder.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sampleData(EmailTemplate $template): array
    {
        $samples = [
            'user_name' => 'John',
            'user_full_name' => 'John Doe',
            'user_email' => 'john@example.com',
            'workspace_name' => 'Acme Corp',
            'reason' => 'Violation of terms of service.',
            'message' => 'Hello from the admin panel!',
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'verification_url' => config('app.url').'/verify/sample',
            'reset_url' => config('app.url').'/reset-password/sample-token',
            'expire_minutes' => '60',
        ];

        $data = [];
        /** @var list<string> $variables */
        $variables = $template->variables ?? [];
        foreach ($variables as $var) {
            $data[$var] = $samples[$var] ?? "[$var]";
        }

        return $data;
    }
}
