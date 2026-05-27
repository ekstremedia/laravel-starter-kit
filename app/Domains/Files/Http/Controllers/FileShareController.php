<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Models\FileShare;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manages share-links created by the owner of a FileItem.
 *
 * Public (unauthenticated) viewing of those links lives in PublicShareController.
 */
class FileShareController extends Controller
{
    public function store(Request $request, FileItem $file): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertCanShare($file, $user, $tenant);
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('share files'), 403, __('files.permission_denied'));

        $maxDays = AppSetting::current()->max_share_days ?? 7;

        $data = $request->validate([
            'expires_in_hours' => 'nullable|integer|min:1|max:'.($maxDays * 24),
            'password' => 'nullable|string|min:4|max:128',
        ]);

        $hours = (int) ($data['expires_in_hours'] ?? ($maxDays * 24));

        $share = FileShare::create([
            'token' => Str::random(32),
            'file_item_id' => $file->id,
            'created_by' => $user->id,
            'expires_at' => now()->addHours($hours),
            'password_hash' => ! empty($data['password']) ? Hash::make($data['password']) : null,
        ]);

        return response()->json([
            'share' => $this->formatShare($share),
            'url' => route('public.share.view', $share->token),
        ]);
    }

    public function index(Request $request, FileItem $file): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertCanShare($file, $user, $tenant);
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('share files'), 403, __('files.permission_denied'));

        $shares = FileShare::where('file_item_id', $file->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => $this->formatShare($s));

        return response()->json(['shares' => $shares]);
    }

    public function destroy(Request $request, FileShare $share): RedirectResponse
    {
        // The file_shares → file_items FK cascades on delete, so a share
        // without its FileItem shouldn't exist in the DB. FileItem does
        // use SoftDeletes though — the default belongsTo relation respects
        // the soft-delete scope and resolves to null once the owner
        // trashes the file, which would TypeError on assertCanShare. Load
        // the relation with `withTrashed()` so the authorization + revoke
        // path keeps working until the item is hard-purged.
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $file = FileItem::withTrashed()->find($share->file_item_id);
        abort_if($file === null, 404);
        $this->assertCanShare($file, $user, $tenant);
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('share files'), 403, __('files.permission_denied'));

        $share->delete();

        return back()->with('success', __('files.share_revoked'));
    }

    /**
     * Generate a one-click signed download URL — no password, expiry comes
     * from Laravel's URL signer. Good for quick file-only sharing.
     */
    public function quickSignedLink(Request $request, FileItem $file): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertCanShare($file, $user, $tenant);
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('share files'), 403, __('files.permission_denied'));

        if ($file->isFolder()) {
            abort(422, __('files.cannot_share_folder_quick'));
        }

        $maxDays = AppSetting::current()->max_share_days ?? 7;
        $hours = (int) $request->integer('hours', 24);
        $hours = max(1, min($hours, $maxDays * 24));

        $url = URL::temporarySignedRoute(
            'public.share.signed',
            now()->addHours($hours),
            ['file' => $file->id],
        );

        return response()->json([
            'url' => $url,
            'expires_at' => now()->addHours($hours)->toIso8601String(),
        ]);
    }

    /**
     * @return array{id:int|string, token:string, url:string, expires_at:string, has_password:bool, view_count:int, last_viewed_at:string|null}
     */
    private function formatShare(FileShare $share): array
    {
        return [
            'id' => $share->id,
            'token' => $share->token,
            'url' => route('public.share.view', $share->token),
            'expires_at' => $share->expires_at->toIso8601String(),
            'has_password' => $share->requiresPassword(),
            'view_count' => $share->view_count,
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
        ];
    }

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('customer');
        if ($tenant instanceof Tenant) {
            return $tenant;
        }
        $slug = config('tenancy.default_customer_slug');
        $fallback = $slug ? Tenant::query()->where('slug', $slug)->first() : null;
        abort_if(! $fallback, 404);

        return $fallback;
    }

    /**
     * Authorise a public-share action. Rules:
     *
     *   - Personal file: only the owner may create/revoke a link. This is
     *     what the original `assertOwns` enforced and the default branch
     *     below preserves it.
     *   - Native company file: the file's uploader (`user_id`) OR a
     *     customer admin can create/revoke. Sharing externally is an
     *     admin-level action — we don't want any ordinary member
     *     exposing another member's upload publicly.
     *   - Linked personal file (a personal file surfaced via
     *     company_file_links): only the owner can share it. Admins who
     *     want public exposure should ask the owner to share, or copy
     *     the file as a native company upload first.
     */
    private function assertCanShare(FileItem $item, User $user, Tenant $tenant): void
    {
        if ($item->tenant_id !== $tenant->id) {
            throw new AccessDeniedHttpException;
        }

        $isOwner = $item->user_id === $user->id;

        if ($item->scope === FileItem::SCOPE_COMPANY) {
            $canManage = $user->isSuperAdmin() || (bool) $user->can('manage company files');
            if (! $isOwner && ! $canManage) {
                throw new AccessDeniedHttpException;
            }

            return;
        }

        if (! $isOwner) {
            throw new AccessDeniedHttpException;
        }
    }

    private function assertFeatureAvailable(Tenant $tenant, User $user): void
    {
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }
        if (! $tenant->files_feature_enabled) {
            abort(404);
        }
        if (! ($user->settings()->resolved()['files_enabled'] ?? false)) {
            abort(403);
        }
    }
}
