<?php

declare(strict_types=1);

namespace App\Domains\Files\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Resolves the polymorphic FileItem owner from a request's owner_type /
 * owner_id inputs — the single source of truth for "whose files am I touching?"
 *
 * Shared by FileItemController, FileTrashController, and EnsureStorageAvailable
 * so the whitelist + morph-alias resolution stays in one place. New file-owning
 * entities only need a `config('files.allowed_owner_types')` entry + morph map
 * alias; this resolver then accepts them everywhere automatically.
 */
class OwnerResolver
{
    /**
     * Resolve the owner from the request, or fall back to $fallback (the
     * authenticated user for personal files) when no owner is specified.
     * Aborts 422 for a type not on the allow-list, 404 when the row is gone.
     */
    public static function fromRequest(Request $request, Model $fallback): Model
    {
        $type = $request->input('owner_type');
        $id = $request->input('owner_id');

        if (! is_string($type) || ! is_numeric($id)) {
            return $fallback;
        }

        // The client sends the morph alias (what's stored in owner_type), not
        // a raw class path — resolve it through the morph map and only allow
        // registered owner classes to block crafted payloads probing classes.
        $class = Relation::getMorphedModel($type);
        $allowed = config('files.allowed_owner_types', []);

        if ($class === null || ! in_array($class, $allowed, true) || ! class_exists($class)) {
            abort(422, 'Unknown owner type.');
        }

        $resolved = $class::query()->find((int) $id);

        if ($resolved === null) {
            abort(404);
        }

        return $resolved;
    }
}
