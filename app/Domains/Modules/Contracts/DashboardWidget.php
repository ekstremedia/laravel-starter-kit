<?php

declare(strict_types=1);

namespace App\Domains\Modules\Contracts;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

/**
 * A widget a module contributes to the per-user, customizable workspace
 * dashboard. Registered in config/dashboard.php; rendered by Dashboard.vue,
 * which maps `component()` to a Vue component and resolves `titleKey()` via
 * i18n. Each user can hide widgets (stored in their settings) — default on.
 */
interface DashboardWidget
{
    /** Stable unique key (used in user prefs + the Vue component map). */
    public function key(): string;

    /** The module this widget belongs to; the widget hides when it's disabled. Null = always available. */
    public function moduleKey(): ?string;

    /** i18n key for the widget title (resolved on the frontend). */
    public function titleKey(): string;

    /** Command Icon name for the widget header. */
    public function icon(): string;

    /** Vue component name registered in Dashboard.vue's widget map. */
    public function component(): string;

    /**
     * The widget's payload for a given workspace + user. Only called for
     * widgets the user has enabled.
     *
     * @return array<string, mixed>
     */
    public function data(Workspace $workspace, User $user): array;
}
