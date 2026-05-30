# Laravel Starter Kit — agent guide

An opinionated, batteries-included Laravel starter. Generic foundation — reshape for your domain. This file orients an agent fast; keep it lean and current.

## Stack

- **Backend:** Laravel 13 · PHP 8.4 · PostgreSQL 17 · Redis 7
- **Frontend:** Vue 3 + TypeScript · Inertia.js v3 (SSR-ready, opt-in) · Pinia · Tailwind v4 · PrimeVue v4 (escape hatch only — see Command design system) · vite-plugin-pwa (opt-in)
- **Auth:** Fortify (headless) + Sanctum · Spatie Permission (teams) · Socialite
- **Async/real-time:** Redis queues + Horizon · Reverb (WebSockets) + Laravel Echo · generic live-update broadcasts (see Real-time)
- **Media/files:** spatie/laravel-medialibrary · Gotenberg (doc→image) · ffmpeg
- **Ops:** Pulse · Sentry (opt) · spatie backup/activitylog · opcodesio log-viewer · lab404 impersonate
- **Tests/QA:** Pest 4 · Larastan 5 · Pint · Vitest 4 · Husky pre-commit · GitHub Actions

## Getting started

Docker only — no local PHP/Node needed.

```sh
make build          # creates .env, builds image, starts stack, migrates + seeds
# → http://localhost:8120   (Mailpit: http://localhost:8126)
```

Seeded admin: `admin@example.test` / `password` (or set `DEV_EASY_LOGIN_ENABLED=true` for a one-click login button). Custom hostname/port: see README "Advanced setup".

## Commands (run in the `app` container)

```sh
docker compose exec app php artisan <cmd>
docker compose exec app composer <cmd>
docker compose exec app npm <cmd>
make test           # Pest (serial)        make test-all   # full CI: pint + stan + pest + tsc + vitest
make test-parallel  # paratest (~6× faster)  make stan       # Larastan level 5
make shell  make logs  make rebuild        # rebuild = migrate:fresh --seed (local only)
```

After editing PHP run `vendor/bin/pint --dirty`. CI runs `pint --test` over **all** files, so a green pre-commit can still fail CI — reproduce with `vendor/bin/pint --test`.

## Architecture

Inertia SPA: Laravel owns routing/auth/controllers; Vue renders `resources/js/Pages/*`. Use `Inertia::render()`, not Blade views.

**Performance posture:** pages are per-page code-split (lazy `import.meta.glob` in `app.ts` — no `eager`); heavy libs load lazily (ApexCharts via `Components/Command/LazyChart.vue`, leaflet/markdown-it via dynamic `import()`). Vite splits stable vendor chunks (`vendor-vue/inertia/primevue/i18n`). Adopt Inertia v3 features when they help: `<Link prefetch>` on nav, `Inertia::defer()` for non-first-paint shared props (e.g. `available_workspaces`), `<WhenVisible>` for below-the-fold data. History encryption is on by default. **State:** Pinia (`resources/js/stores/*`) for genuinely-shared/new state (`realtime`, `notifications`); the existing module-singleton composables (`useTweaks`, `useSettings`, …) stay as-is — don't migrate them.

### Domain modules — `app/Domains/*`

Backend code lives in domain modules, **not** flat `app/Http`/`app/Models`. Each domain holds its own `Models/`, `Http/{Controllers,Middleware,Requests,Resources}/`, `Policies/`, `Events/`, `Jobs/`, `Services/`, `Console/`, `Providers/` as needed:

| Domain | Owns |
|---|---|
| `Auth` | Fortify actions, Login/Register responses, Socialite, dev-login, `FortifyServiceProvider` |
| `Users` | `User`, `UserSetting`, `PersonalAccessToken`, profile/avatar/token controllers, user commands |
| `Access` | `Role`, `Permission`, role/permission admin, super-admin & workspace-admin middleware |
| `Files` | `FileItem`/`FileShare`/`CompanyFileLink`, `FileOwner` contract, `HasFiles`/`HasFileQuota` concerns, `OwnerResolver`, policies, controllers, resources, jobs, events, `StorageUsageService` |
| `Assets` | Demo file-owning entity (removable — see Files) |
| `Workspaces` | `Workspace`, `Support\WorkspaceContext` (resolver), `Concerns\BelongsToWorkspace` (global scope), `ResolveWorkspace` + `BindDefaultWorkspace`, workspace controllers, `CreateWorkspace`, `WorkspaceInvitation(Controller)`, `WorkspaceMembership` |
| `Notifications` | `EmailTemplate`, `MailSetting`, `MjmlCompiler`, mailables, notifications, mail/notification controllers |
| `Chat` | `Conversation`, `Message`, `ChatController`, `MessageSent` |
| `Settings` | `AppSetting`, settings controllers, `EnforceAppSettings` |
| `Operations` | Admin dashboards (overview/monitoring/backups/storage/system/health), `Activity`, Horizon provider, impersonation |

Global infra stays at the `App\` root: base `App\Http\Controllers\Controller`, global middleware (`HandleInertiaRequests`, `SecurityHeaders`, `RequestId`, `SetLocaleFromUser`, `EnsureUserIsNotBanned`), `AppServiceProvider`.

**Conventions when adding/moving code:**
- Namespace mirrors the path: `App\Domains\Files\Services\StorageUsageService`.
- **Morph map** (`AppServiceProvider::boot`): polymorphic `*_type` columns store stable aliases (legacy `App\Models\*` strings + clean aliases like `asset`), so models move without a data backfill. **Compare morph types with `$model->getMorphClass()`, never `Model::class`.**
- **Factories stay flat** in `database/factories` (`Database\Factories\<Name>Factory`) — `Factory::guessFactoryNamesUsing` + a `protected $model` on each factory wire them up.
- **Domain commands** auto-register via `->withCommands([app/Domains])` in `bootstrap/app.php`.
- A controller moved out of `App\Http\Controllers` must `use App\Http\Controllers\Controller;` explicitly. A model referencing a sibling in another domain needs an explicit `use` (same-namespace short names break across domains).
- When moving a model, update its references in `config/*` (auth, permission, workspaces, activitylog), `phpstan.neon`, and `bootstrap/*`.
- URLs are generated from `APP_URL` (`URL::forceRootUrl` in `AppServiceProvider`) — the bundled nginx `fastcgi_params` pass `$host` (no port), so don't rely on the request host for absolute URLs.

## Coding rules

**PHP** — curly braces always; constructor property promotion; explicit return types + param hints; PHPDoc array shapes; `php artisan make:* --no-interaction` to scaffold.

**Vue / frontend** — every visible string uses `t('key')` (vue-i18n); single root element; PrimeVue labels use `:label="t(...)"`; **compose Command primitives + tokens, never raw Tailwind color utilities** (see Command design system). Check sibling files before adding components.

**Testing** — every change gets a Pest test; use factories + `fake()`; `make test` serial, CI parallel. Gotchas:
- `MjmlCompiler` is faked in `tests/Pest.php` `beforeEach` (real `npx mjml` is slow); the one real-compilation test news up `MjmlCompiler` directly. Don't remove the fake.
- `Notification::fake()` doesn't persist — seed real notifications via `$user->notify(...)` *before* faking if the code reads `unreadNotifications()`.
- Use `postJson` (not `post` + `X-Requested-With`) to test validation errors as 422 JSON.

**Localization** — English (`en`) + Norwegian (`no`); translate everything.
- `t('key')` in `.vue`; update **both** `resources/js/i18n/{en,no}.ts` in the same commit. Group by domain (`admin.users.*`, `common.*`).
- Backend `__()` auto-respects the user's locale via `SetLocaleFromUser` middleware. **Queued notifications run at default locale** — pass the recipient locale as the 3rd `__()` arg in `toArray()`/`toMail()` (`User::preferredLocale()` covers `MailMessage`).
- Backend message files: `lang/{en,no}/*.php`.
- A literal `@` in an i18n value breaks vue-i18n — escape as `{'@'}`.

## Features

**Admin** (`/admin/*`, super-admin gate): overview, users, workspaces, roles, permissions, settings, mail, storage, backups, system, monitoring (`/admin/monitoring` embeds Horizon/Pulse/log-viewer). Workspace-admins manage their own members at `/w/{slug}/members`.

**Auth** — Inertia pages via `App\Domains\Auth\Providers\FortifyServiceProvider`; customizations in `app/Domains/Auth/`. `config('fortify.home')` = `/app` (post-login landing). Flows: login, register, email verify, password reset, 2FA (TOTP + recovery), password confirm. Don't `validateWithBag()` in Fortify actions unless you wire the bag through `useForm()`.

**Roles & permissions** (Spatie, **teams** = workspaces) — seeded `Admin`/`Editor`/`User`; `is_super_admin` is a column bypass (not a role), enforced via `Gate::before`. Roles are per-workspace (team-scoped); assign from a user's show page / workspace members panel.

**Files + entity documents** — personal files at `/files`, company files at `/files/company`, and **any entity can own a file tree** via the `FileOwner` contract:
- Adopt `HasFiles` (+ optional `HasFileQuota`), implement `FileOwner`, register a morph alias in `AppServiceProvider`, add the class to `config('files.allowed_owner_types')`, and a route + `<EntityFiles>` browser component. The **Assets** domain is the reference implementation (gated by `ASSETS_ENABLED`, deletable wholesale).
- File mutations: personal → `FileItemController`; entity-owned → generic `EntityFileController` (owner via `owner_type`/`owner_id`, resolved by `OwnerResolver`, authorized by `FileItemPolicy` + the owner's `canManageFiles`).
- Previews: image conversions (thumb/medium/large/xlarge); video poster + H.264 transcode; PDF/Office → image via Gotenberg. Conversions run on the queue; `FileItemUpdated` broadcasts when done.
- **Quota** resolution (`StorageUsageService::effectiveQuota`): per-row override → owner-type default → app default → unlimited (`null`=inherit, `-1`=unlimited, `0`=blocked, `N`=cap). `EnsureStorageAvailable` middleware is owner-aware.
- Sharing: `/share/{token}` public pages (`file_shares`, optional password + expiry); quick links use signed URLs. Soft-delete + trash with cascade; `PurgeTrashedFileItems` hard-deletes after the retention window.
- **Queue worker gotcha:** after `composer require`, run `php artisan horizon:terminate` so workers respawn with a fresh autoloader (else "Class X not found").

**Multi-tenancy (workspaces)** — optional, **single-database row-level** (NO schema/DB per workspace; `stancl/tenancy` was removed). Every workspace-scoped table has a `workspace_id`; the `BelongsToWorkspace` trait (`app/Domains/Workspaces/Models/Concerns`) adds a global scope that filters to the current workspace and auto-stamps `workspace_id` on create — so you can't leak across workspaces by forgetting a `where`. Current workspace = the `App\Domains\Workspaces\Support\WorkspaceContext` resolver (singleton), set per request by `ResolveWorkspace` (multi-tenant, `/w/{workspace}`) or `BindDefaultWorkspace` (single-tenant, root). The scope is **inert when no workspace is active** (central/admin routes) so admins query across workspaces; bypass explicitly with `Model::withoutGlobalScope('workspace')`. Per-workspace roles via Spatie teams (`team_id = workspace id`), synced through `WorkspaceMembership`. The `Workspace` model is plain Eloquent.
- `WORKSPACES_ENABLED` (`config('workspaces.enabled')`): true → `/w/{workspace}/*` routes + switcher/picker; false → workspace routes mount at the **root**, one default workspace, no chrome (a normal Laravel app).
- `WORKSPACES_REGISTRATION_MODE`: `create_own` (sign-up creates its own workspace, becomes Admin — via the `CreateWorkspace` action) | `join_default` (auto-join default). Branch lives in `CreateNewUser`.
- Invitations: `WorkspaceInvitation` + `WorkspaceInvitationController` — admins invite by email under `/members/invitations`; the public `/invitations/{token}` accept threads guests through register/login; `WorkspaceLandingController` finishes deferred accepts.
- `exists:` validation runs on the default connection — validate users via a closure rule (see `FileItemController::existsFileItemRule`).

**Layered feature flags** (global → per-workspace → per-user): `AppSetting::current()->{x}_feature_enabled` → `workspaces.{x}_feature_enabled` (a plain cast column on `Workspace`) → `UserSetting::$defaults['{x}_enabled']`. Backends abort 404/403; nav links check all three via shared Inertia props.

**Chat** (off by default, `CHAT_ENABLED`) — 1:1 + group at `/chat`; `Conversation`/`Message` (optionally encrypted); broadcasts on `private:chat.conversation.{id}`; `NewChatMessageNotification` is broadcast-only (navbar badge, **not** the bell inbox) — don't "fix" it to `database`.

**Notifications** — DB-backed + MJML email. Add one: `php artisan make:notification --no-interaction`, use `UsesEmailTemplate`, `via()` → `['database','mail']`, `toArray()` → `['title','message','icon']`, then declare the email in the **`config/mail-templates.php`** registry (slug → `variables` + per-locale `en`/`no` copy) and run `php artisan mail:sync-templates` (the `EmailTemplateSeeder` calls the same command). Sync never clobbers dashboard edits — it only seeds copy on first create; `--fresh` resets to registry defaults, `--prune` drops removed slugs. `renderTemplate()` warns (non-prod) if a slug isn't in the registry. Admins edit content (all locales) at `/admin/mail` → **Email Templates** (delegatable to non-super-admins via the user page's *Platform access* → *Manage email templates* toggle, backed by `users.platform_permissions` + the `manage email templates` gate). The shared wrapper/branding (colours, font, header, footer) is super-admin-only at `/admin/mail` → **Layout** (`MailLayout` singleton; saving dispatches `RecompileEmailTemplatesJob` to rebuild every template's cached HTML).

**Settings** — app settings at `/admin/settings` use a section registry (one array + a matching block in `Admin/AppSettings.vue`); add sections there. User prefs live in `user_settings.settings` (JSONB) — extend `UserSetting::$defaults` + the `UserSettingsShape` PHPDoc (no migration).

**Real-time / live updates** — the app should *feel live*: when one user mutates a list, everyone viewing it should see it without a manual refresh. The reusable pattern (full guide: **`docs/realtime-and-broadcasting.md`**):
- Backend: dispatch the generic `App\Support\Events\ResourceChanged` after a create/update/delete via the `App\Support\Concerns\BroadcastsResourceChanges` trait (`$this->broadcastResourceChanged($resource, $action, $id, $workspaceId)`). Workspace entities pass the workspace id (→ `workspace.{id}.resources` channel); central super-admin CRUD omits it (→ `admin.resources`). Payload is a lightweight `{resource, action, id}` ping only — no row data (avoids leaking to subscribers). **Dispatch explicitly in the controller** (not a model observer — keeps it out of seeders/tests). Wired across Users/Roles/Permissions/Workspaces/Modules + Equipment/EquipmentCategories/Members.
- Frontend (preferred for lists): `useLiveList({ channel, resource, source, fetchOne, refreshOnly, bulkReload })` is **surgical** — on a ping it fetches **only the changed row** from a per-resource `live-row` JSON endpoint (which reuses the index's row-shaper) and patches it in place; counts refresh via a tiny partial reload; bulk pings fall back to one reload. The client never re-fetches the whole list. Render from the returned `rows` ref (for a paginated table, feed it `{ ...prop, data: rows.value }`). `useLiveReload(() => channel, { resource, only })` is the simpler whole-prop-reload alternative for tiny/stats-only surfaces.
- **Always degrades gracefully** — a no-op when Echo/Reverb is down, so nothing breaks; it's a supplement, not a dependency. The existing live surfaces (chat, files via `CompanyFilesChanged`/`FileItemUpdated`, admin health) follow the same "signal → re-fetch" shape. The topbar `LiveIndicator` (Pinia `realtime` store) shows connection status.
- **New interactive list/index pages should opt into this pattern.** Requires `BROADCAST_CONNECTION=reverb` + a running Horizon worker (both default).

**SSR (opt-in, off by default)** — full Inertia SSR is wired but disabled: `resources/js/ssr.ts` mirrors `app.ts` (never imports `./bootstrap`). Enable with `INERTIA_SSR_ENABLED=true` + `npm run build:ssr` + `supervisorctl start inertia-ssr` (the supervisor program is `autostart=false`). **Browser-only code must be SSR-guarded** (`typeof window/localStorage === 'undefined'`, or keep it in `onMounted`) — see `useSettings`/`useUserChannel` for the idiom.

**PWA (opt-in, off by default)** — `VITE_PWA_ENABLED=true` + rebuild emits a manifest + conservative service worker (precache built assets, `public/offline.html` fallback; **never caches HTML/JSON/`/storage` media/POST** — safe for an auth app). Manual guarded SW registration in `app.ts`; `useInstallPrompt` for the install button; icons regenerate from `resources/icons/source.svg` via `npm run pwa:icons`.

## Command design system

UI is built on **Command** — token-driven Vue primitives in `resources/js/Components/Command/`, styled by CSS vars in `resources/css/tokens.css`. Compose primitives; use Tailwind only for layout (grid/flex/spacing).

- Tokens via `data-theme`/`data-accent`/`data-density` on `<html>` (set by `useTweaks()`, persisted, applied pre-hydration). Themes: dark/hc/light. Always use `var(--bg|--fg|--accent|--border|…)`, never hardcoded colors. Utility classes: `.cmd-card`, `.cmd-mono`, `.cmd-uc`.
- Primitives: `Dialog` (use for **every** modal), `Button`, `Field`, `Select`, `Toggle`, `Icon`, `DataTable`, plus the shell `CommandLayout`/`Rail`/`Topbar` (`defineOptions({ layout: CommandLayout })`). Sidebar nav is data-driven in `composables/useSidebarItems.ts`.
- PrimeVue remains only for `DataTable`, `MultiSelect`, `ConfirmDialog`, `Password`. Use `<ConfirmDialog>` + `useConfirm()` (never `window.confirm`); name confirm groups to avoid cross-fire. Flash → toast via `useFlashToast`; in-flow toasts via `useCommandToasts`.

## Laravel Boost MCP

`.mcp.json.example` → copy to `.mcp.json`, set the project path; it runs `php artisan boost:mcp` in the `app` container. **Prefer Boost tools over shell/grep/guessed URLs:**
- `database-query` (read-only SQL) / `database-schema` — don't `psql` or read migrations to guess schema.
- `read-log-entries` / `last-error` / `browser-logs` for logs & errors.
- `search-docs` — version-pinned docs for the whole Laravel/Inertia/Pest/Tailwind stack; use **before** web search and before code changes.
- `application-info`, `get-absolute-url`. Chrome DevTools MCP (`mcp__chrome-devtools__*`) drives a real browser for UI verification.
- Boost has **no** tinker tool — for ad-hoc state use `docker compose exec app php artisan tinker --execute '…'` (single quotes). `database-query` rejects writes.

Project skills auto-activate by domain — use them: `fortify-development`, `laravel-permission-development`, `medialibrary-development`, `echo-development`, `inertia-vue-development`, `pest-testing`, `tailwindcss-development`, `configuring-horizon`, `pulse-development`, `scout-development`, `socialite-development`, `laravel-backup`, `laravel-best-practices`.

## Maintenance checklist

- New UI string → both `en.ts` + `no.ts` (and `lang/{en,no}/*.php` for backend).
- New user setting → `UserSetting::$defaults` + `UserSettingsShape` PHPDoc + TS interface.
- New workspace column → migration + cast on `Workspace` + factory (`Workspace` is a plain Eloquent model).
- New workspace-scoped entity → `use BelongsToWorkspace` (auto-scope + auto-stamp); migration with a `workspace_id` FK; morph-map alias if morphed. See **`docs/adding-a-workspace-entity.md`**.
- New file-owning entity → mirror `app/Domains/Assets` (`BelongsToWorkspace` + `FileOwner` + `HasFiles`, morph alias, `allowed_owner_types`, route + `<EntityFiles>`).
- New interactive list/CRUD page → make it **live**: dispatch `ResourceChanged` from the controller mutations + `useLiveReload` on the page (graceful when WS is off). See **`docs/realtime-and-broadcasting.md`**.
- New Vue page/form → reach for **Inertia v3** helpers (`useForm`/`<Link prefetch>`/deferred props) over hand-rolled fetch; keep browser-only code SSR-guarded.
- New/changed component → **verify it at mobile/tablet/desktop widths with the Chrome DevTools MCP** (`resize_page` + screenshots) and confirm behaviour/URLs/no console errors with the **Laravel Boost MCP** (`browser-logs`, `last-error`).
- New package → note it in the Stack list (+ README) and keep it lazy-loaded if heavy.
- Keep behavior generic (no domain-specific nouns/seed data); prefer env-driven config; run `make test-all` before finishing.
