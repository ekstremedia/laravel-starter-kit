<?php

declare(strict_types=1);

/*
 * Workspaces (multi-tenancy) configuration.
 *
 * Isolation is row-level: every workspace-scoped table carries a `workspace_id`
 * and the BelongsToWorkspace global scope filters queries to the current
 * workspace (resolved per request by ResolveWorkspace into the
 * App\Domains\Workspaces\Support\WorkspaceContext singleton). There is NO
 * schema- or database-per-workspace — one database, one schema.
 */
return [
    /*
     * Controls the multi-tenant UI/behaviour. When false the app behaves like
     * a normal single-workspace Laravel app (no /c/ prefix, no workspace
     * switcher/picker); when true users can belong to several workspaces with
     * per-workspace roles. Defaults to true to preserve existing installs.
     */
    'enabled' => env('WORKSPACES_ENABLED', true),

    /*
     * Slug of the workspace new registrations join when registration_mode is
     * "join_default" (and the implicit single workspace when tenancy is off).
     */
    'default_workspace_slug' => env('WORKSPACES_DEFAULT_WORKSPACE', 'default'),

    /*
     * How a new sign-up gets a workspace (only consulted when tenancy is on):
     *   - 'create_own'   → the user creates their OWN workspace and becomes its
     *                      admin (self-serve, e.g. the cars/medicines app).
     *   - 'join_default' → the user auto-joins the default workspace with the
     *                      app's default role (internal/team apps).
     * When tenancy is OFF this is ignored — everyone shares the one workspace.
     */
    'registration_mode' => env('WORKSPACES_REGISTRATION_MODE', 'join_default'),

    'database' => [
        // The single connection everything runs on. Kept under this key so the
        // existing config('workspaces.database.central_connection') call sites
        // (e.g. WorkspaceMembership transactions) keep working.
        'central_connection' => env('DB_CONNECTION', 'pgsql'),
    ],
];
