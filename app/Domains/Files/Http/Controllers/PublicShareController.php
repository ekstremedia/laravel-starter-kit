<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Models\FileShare;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unauthenticated access to shared files and folders.
 *
 * Two flavours:
 *   - `/share/{token}` → full share (owner-created, tokenized, optional pass).
 *   - `/share/signed/file/{file}` → single-file quick link (Laravel-signed).
 */
class PublicShareController extends Controller
{
    public function view(Request $request, string $token): Response|RedirectResponse
    {
        $share = FileShare::where('token', $token)->firstOrFail();

        if ($share->isExpired()) {
            abort(410, __('share.expired'));
        }

        if ($share->requiresPassword() && ! $this->isUnlocked($share)) {
            return Inertia::render('Share/Password', [
                'token' => $share->token,
                'action' => route('public.share.unlock', $share->token),
            ]);
        }

        /** @var FileItem $item */
        // FileItemResource reads `companyLink` + `user` off the model; eager
        // load both here so the public share page doesn't N+1 on folders.
        $item = $share->fileItem()->with(['media', 'companyLink', 'user'])->firstOrFail();

        $share->forceFill([
            'view_count' => $share->view_count + 1,
            'last_viewed_at' => now(),
        ])->save();

        return Inertia::render('Share/Show', [
            'item' => (new FileItemResource($item))->toArray($request),
            'children' => $item->isFolder()
                ? FileItem::where('parent_id', $item->id)
                    ->with(['media', 'companyLink', 'user'])
                    ->orderByRaw("case when type = 'folder' then 0 else 1 end")
                    ->orderBy('name')
                    ->get()
                    ->map(fn (FileItem $i) => (new FileItemResource($i))->toArray($request))
                    ->all()
                : [],
            'share' => [
                'token' => $share->token,
                // Column is NOT NULL; the ?-> is defensive in case a future
                // migration relaxes that.
                'expires_at' => $share->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $share = FileShare::where('token', $token)->firstOrFail();

        $request->validate(['password' => 'required|string']);

        if (! $share->password_hash || ! Hash::check((string) $request->input('password'), $share->password_hash)) {
            return back()->withErrors(['password' => __('share.wrong_password')]);
        }

        session()->put("share.unlocked.{$token}", true);

        return redirect()->route('public.share.view', $token);
    }

    public function download(Request $request, string $token, int $fileId): BinaryFileResponse
    {
        $share = FileShare::where('token', $token)->firstOrFail();

        if ($share->isExpired()) {
            abort(410);
        }
        if ($share->requiresPassword() && ! $this->isUnlocked($share)) {
            abort(403);
        }

        // Use the relation's query so a dangling share (file deleted before
        // cascade kicked in) 404s cleanly instead of dereferencing null.
        /** @var FileItem $root */
        $root = $share->fileItem()->firstOrFail();
        /** @var FileItem $target */
        $target = FileItem::findOrFail($fileId);
        if ($target->id !== $root->id && ! $this->isDescendantOf($target, $root)) {
            abort(403);
        }

        $media = $target->getFirstMedia('file');
        abort_if(! $media, 404);

        return response()->download($media->getPath(), $target->name);
    }

    public function signedDownload(Request $request, int $file): BinaryFileResponse
    {
        // Defense in depth — the route already carries the `signed` middleware,
        // but a direct call here without the signature check would also bypass
        // expiry, so we assert it explicitly.
        abort_unless($request->hasValidSignature(), 403);

        /** @var FileItem $item */
        $item = FileItem::findOrFail($file);
        abort_if($item->isFolder(), 404);

        $media = $item->getFirstMedia('file');
        abort_if(! $media, 404);

        return response()->download($media->getPath(), $item->name);
    }

    private function isUnlocked(FileShare $share): bool
    {
        return (bool) session("share.unlocked.{$share->token}");
    }

    private function isDescendantOf(FileItem $candidate, FileItem $ancestor): bool
    {
        $cursor = $candidate;
        while ($cursor->parent_id !== null) {
            if ($cursor->parent_id === $ancestor->id) {
                return true;
            }
            $cursor = FileItem::find($cursor->parent_id);
            if (! $cursor) {
                return false;
            }
        }

        return false;
    }
}
