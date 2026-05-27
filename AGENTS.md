# Laravel Starter Kit

An opinionated starter for new Laravel products. Generic foundation — reshape for your domain.

## Tech Stack

- **Backend:** Laravel 13, PHP 8.4, PostgreSQL 17, Redis 7
- **Auth:** Fortify (headless) + Sanctum (SPA session + API tokens)
- **Frontend:** Vue 3 + TypeScript, Inertia.js v3, Tailwind CSS v4, PrimeVue v4
- **Testing:** Pest, Larastan (level 5), Vitest (frontend)
- **Queues:** Redis + Horizon (Supervisor)
- **Monitoring:** Pulse (`/pulse`), Horizon (`/horizon`), Sentry (optional)
- **Other:** Docker, vue-i18n, Spatie Permission, Spatie Activitylog, Laravel Reverb, MJML email templates

## Commands

All commands run inside the `app` container:

```sh
docker compose exec app php artisan <cmd>
docker compose exec app composer <cmd>
docker compose exec app npm <cmd>
docker compose exec app vendor/bin/pint --dirty --format agent
docker compose exec app php artisan test --compact
```

## Coding Rules

### PHP
- Curly braces for all control structures, even single-line
- PHP 8 constructor property promotion
- Explicit return types and parameter type hints
- PHPDoc with array shape types
- Run `vendor/bin/pint --dirty --format agent` after modifying PHP files
- **CI runs `pint --test` on all files**, which can flag issues outside changed hunks. If CI fails after a green pre-commit, run `pint --test` locally to reproduce
- Use `php artisan make:*` with `--no-interaction` to scaffold files

### Vue / Frontend
- Every visible string must use `t('key')` from vue-i18n (see Localization section)
- Vue components must have a single root element (Inertia requirement)
- PrimeVue label props use dynamic binding: `:label="t('...')"` not `label="..."`
- **Use Command primitives + tokens, NOT raw Tailwind color utilities.** All UI chrome (buttons, inputs, dialogs, cards, dropdowns) must use the Command design system — see the "Command design system" section below. Do not write `bg-white`, `dark:bg-dark-*`, `bg-indigo-*`, `text-indigo-*`, `border-gray-*` for UI surfaces; they bypass the tokens and break theme / accent switching
- Check sibling files for conventions before creating new components

### Testing
- Every change must be tested. Write or update a Pest test, then run it
- Use factories for models, `fake()` for test data
- `php artisan make:test --pest {name}` for feature tests, `--unit` for unit tests
- Do NOT delete tests without approval
- `make test` runs serially (clearer failures when iterating on one test); `make test-parallel` uses paratest with one DB per worker (~6× faster on a warm machine). CI runs parallel by default
- **`MjmlCompiler` is faked in `tests/Pest.php` `beforeEach`** — real `npx mjml` is ~600 ms per template × 18 seeded templates × `RefreshDatabase`, so the suite would take 2+ minutes otherwise. The one test that exercises real compilation instantiates `new MjmlCompiler` directly to bypass the binding. Don't remove the fake
- **`Notification::fake()` does not persist to the DB** — tests that exercise code reading `unreadNotifications()` must seed real notifications via `$user->notify(...)` *before* calling `Notification::fake()`, otherwise the query finds nothing
- **Use `postJson`, not `post` + `X-Requested-With`, to test JSON validation errors.** `postJson` sets `Accept: application/json` so Laravel's ValidationException renders as 422 JSON. A bare XHR without that header crashes inside Inertia's exception rendering with `Call to a member function all() on array`

## Architecture

Inertia SPA: Laravel handles routing/auth/controllers, Vue renders UI from `resources/js/Pages`.

### Domain modules (`app/Domains/*`)

Backend code is organised into domain modules, not flat `app/Http`, `app/Models`, etc. Each domain owns its own `Models/`, `Http/Controllers/`, `Http/Middleware/`, `Http/Requests/`, `Http/Resources/`, `Policies/`, `Events/`, `Jobs/`, `Services/`, `Console/`, `Providers/` as needed:

- **Auth** — Fortify actions, login/register responses, Socialite, dev-login, `FortifyServiceProvider`
- **Users** — `User`, `UserSetting`, `PersonalAccessToken`, profile/avatar/token controllers, user commands
- **Access** — `Role`, `Permission`, role/permission admin, super-admin & customer-admin middleware
- **Files** — `FileItem`/`FileShare`/`CompanyFileLink`, `FileOwner` contract, `HasFiles` concern, policies, controllers, resources, jobs, events, `StorageUsageService`
- **Chat** · **Tenancy** · **Notifications** · **Settings** · **Operations** (admin dashboards, `Activity`, Horizon provider)

Genuinely global infra stays at the `App\` root: the base `App\Http\Controllers\Controller`, global middleware (`HandleInertiaRequests`, `SecurityHeaders`, `RequestId`, `SetLocaleFromUser`, `EnsureUserIsNotBanned`), and `AppServiceProvider`.

**Conventions when adding/moving code:**
- Namespace mirrors the path: `App\Domains\Files\Services\StorageUsageService`.
- **Polymorphic morph map** (`AppServiceProvider::boot`): `*_type` columns store stable aliases keyed by the original `App\Models\*` strings, so models can move without a data backfill. Compare morph types with `$model->getMorphClass()`, never `Model::class`.
- **Factories stay flat** in `database/factories` (`Database\Factories\<Name>Factory`); `Factory::guessFactoryNamesUsing` + a `protected $model` on each factory wires them to domain models.
- **Domain commands** auto-register via `->withCommands([app/Domains])` in `bootstrap/app.php`.
- A controller moved out of `App\Http\Controllers` must add `use App\Http\Controllers\Controller;` explicitly.
- A model referencing a sibling in another domain needs an explicit `use` (same-namespace short names break across domains).
- `config/*` (auth, permission, tenancy, activitylog, log-viewer), `phpstan.neon`, and `bootstrap/*` reference domain FQCNs — update them when moving a model.

### Admin Section (`/admin/*`, gated by `role:Admin`)

`/admin` overview, `/admin/users` CRUD, `/admin/roles`, `/admin/permissions`, `/admin/activity`, `/admin/mail` (SMTP + MJML template editor), `/admin/settings`, `/admin/backups`, `/admin/system`, `/horizon`, `/pulse`, `/log-viewer`

### Authentication (Fortify + Sanctum)

Views rendered as Inertia pages via `App\Domains\Auth\Providers\FortifyServiceProvider`. Custom behavior in `app/Domains/Auth/Actions/`, `app/Domains/Auth/Http/Responses/Login|RegisterResponse.php`.

`config('fortify.home')` is `/app` (the post-login landing). Flows: login, registration, email verification, password reset, 2FA (TOTP + recovery codes), password confirmation.

**Do not use `validateWithBag()`** in Fortify actions unless you also wire the error bag through Inertia's `useForm()`.

### Roles & Permissions (Spatie)

Seeded: `Admin` (all), `Editor` (dashboard, resources, settings, profile), `User` (dashboard, settings, profile — assigned on registration). Shared on every Inertia request as `auth.user.roles` / `auth.user.permissions`.

### Chat (optional, off by default)

Real-time 1:1 + group messaging at `/chat`, gated by `CHAT_ENABLED` env + `chat.enabled` middleware. Routes in `routes/web.php` under the chat group; controller in `App\Domains\Chat\Http\Controllers\ChatController`. Models: `Conversation`, `Message` (body optionally encrypted at rest when `CHAT_ENCRYPTION_ENABLED=true`), pivot `conversation_user` with `last_read_at`. Messages broadcast to `private:chat.conversation.{id}` via the `MessageSent` event; `NewChatMessageNotification` fans out to each other participant on the user private channel so navbar badges update live. Attachments are stored via `spatie/laravel-medialibrary` on the `attachments` collection (whitelisted mime types) with an image `thumb` conversion.

Frontend: `resources/js/Pages/Chat.vue`, dropdown in `Components/Chat/ChatDropdown.vue`, thread in `Components/Chat/MessageThread.vue`. Shared state composables: `useUnreadCounts` (global singleton with a detached `effectScope` watcher) and `useUserChannel` (Echo subscription to `App.Models.User.{id}`). See "Exception: chat messages" under Notifications for why chat doesn't persist to the notification inbox.

## Localization

Supports English (`en`) and Norwegian (`no`). **Every user-facing string must be translated.**

1. Use `t('key')` from vue-i18n in every `.vue` file. Import `useI18n` + `const { t } = useI18n()`.
2. Update **both** `resources/js/i18n/en.ts` and `no.ts` in the same commit.
3. Group keys by domain: `admin.users.*`, `admin.mail.*`, `notifications.*`, `common.*`. Reuse `common.*` for shared words.
4. Dynamic strings: `t('key', { name: value })`.
5. For reactive arrays needing `t()`, wrap in `computed()`.
6. **Backend messages (`__()`) respect the user's locale automatically** via `SetLocaleFromUser` middleware — it reads `$user->settings()['locale']` on every authed request and calls `app()->setLocale(...)`. So validation errors, quota rejections, flash toasts, etc. come out in Norwegian for Norwegian users without per-call locale arguments.
7. **Queued notifications need explicit locale.** The worker runs with the default locale, not the recipient's. Pass the notifiable's locale as the 3rd arg to `__($key, $params, $locale)` inside `toArray()`/`toMail()`. `User::preferredLocale()` is also implemented so Laravel's mail pipeline wraps rendering in `app()->setLocale(...)` for `MailMessage` lines.
8. **Literal `@` in i18n values collides with vue-i18n's link-message operator** — `'user@example.com'` throws *"Invalid linked format"* at render time. Escape with `{'@'}`: `"user{'@'}example.com"`.

## Notifications

DB-backed notification system with MJML email templates. Bell icon in `AppLayout.vue` shows unread count; dropdown fetches from `GET /notifications`.

### When to notify
- Account lifecycle: welcome, verification, password reset, banned
- Customer membership: added/removed
- Admin actions: role changes, 2FA reset
- Workflow events: assignments, mentions, invites

### Adding a notification
1. `php artisan make:notification SomethingHappened`
2. Use `UsesEmailTemplate` trait for MJML emails
3. `via()` → `['database', 'mail']`
4. `toArray()` → `['title' => '...', 'message' => '...', 'icon' => 'pi-...']`
5. Add template rows in `EmailTemplateSeeder` for both `en` and `no` locales
6. Optional admin actions: add "Notify user" checkbox (see `Admin/Users/Edit.vue`)

### Exception: chat messages
`NewChatMessageNotification` deliberately skips the `database` channel and returns `['broadcast']` (+ `'mail'` if the user opted in to immediate emails). Chat pushes to the message badge icon in the navbar, not to the notification bell/inbox — keeping the two streams visually separate was a product decision. The frontend listener in `AppLayout`/`AdminLayout` dispatches on `n.type` to route chat pings to the message counter and everything else to the bell. Don't "fix" this back to `['database', 'mail']`.

## Email Templates (MJML)

Admin-editable email content via `/admin/mail` → Email Templates tab. Templates stored in `email_templates` table with per-locale variants. MJML layout in `resources/views/mjml/layout.blade.php` wraps the editable content (subject, heading, body, CTA button). Compiled HTML cached in `compiled_html` column.

All notifications use the `UsesEmailTemplate` trait which resolves the user's locale and loads the matching template. Custom `VerifyEmailNotification` and `ResetPasswordNotification` replace Laravel defaults.

## Multi-Customer Tenancy (optional, off by default)

`stancl/tenancy` v3, disabled by default. Flip `TENANCY_ENABLED=true` + `migrate:fresh --seed` to activate.

**Vocabulary:** "Customer" = user-facing (URLs, UI, controllers). "Tenant" = package-facing (model, DB tables, config).

| Customer-facing | Tenant-facing |
|----------------|---------------|
| `/c/{customer}/...` URLs | `App\Domains\Tenancy\Models\Tenant`, `tenants` table |
| `/admin/customers` | `config/tenancy.php` |
| `CustomerController`, `useCustomer()` | `InitializeTenancyByPath` |

**When enabled:** path-based `/c/{slug}/...`, PG schema per customer, shared users in `public` schema, `tenant_user` pivot for membership, Admins bypass membership checks.

**When disabled:** routes at root, `tenants` table stays empty, no migration needed to enable later.

**Testing:** `.env.testing` forces `TENANCY_ENABLED=true`. `tests/Pest.php` strips bootstrappers for SQLite. Helpers: `createCustomer()`, `joinCustomer()`, `customerUrl()`.

### Tenancy gotchas (central vs tenant connection)

In production the `DatabaseTenancyBootstrapper` swaps the default DB connection to the tenant schema after `InitializeTenancyByPath` runs. Anything that lives in the **central** schema (users, tenants, media, file_items, file_shares, app_settings, conversations, messages) must opt out of the swap.

- **Pin Eloquent models to the central connection.** Add `getConnectionName(): ?string { return config('tenancy.database.central_connection'); }` to every model whose table is in the central DB. `Message`, `FileItem`, `FileShare`, `AppSetting` already do this.
- **The `exists:` validation rule bypasses model connection.** `'parent_id' => 'exists:file_items,id'` resolves via `DB::connection()` — which is the tenant connection after init — and crashes with "relation does not exist". Use a closure rule that goes through the model: `$fail unless FileItem::whereKey($value)->exists()`. See `FileItemController::existsFileItemRule()`.
- **Raw `DB::table(...)` calls need the central connection explicitly.** `DB::connection(config('tenancy.database.central_connection'))->table('media')`. `StorageUsageService` and `SnapshotStorageUsage` do this.
- **Customer switcher.** `HandleInertiaRequests::availableCustomers()` shares the list (capped at 50). `InitializeTenancyByPath` records `last_customer_slug` in `UserSetting` on every successful init so `CustomerLandingController` can auto-redirect returning users past the picker.

### Layered feature flags

Features with three gates (global + tenant + per-user) follow the pattern used by the personal file system:

1. **Global**: `AppSetting::current()->{feature}_feature_enabled` (admin toggle in `/admin/settings`). Null-safe because `AppSetting` is pinned to central.
2. **Per-customer**: column on `tenants` (e.g. `files_feature_enabled`) + `getCustomColumns()` entry + boolean cast. Admin toggle in `Admin/Customers/Edit.vue`.
3. **Per-user**: JSON key in `UserSetting::$defaults` (e.g. `files_enabled`). Admin toggle in the user list.

Backend controllers abort 404 (global/tenant off) or 403 (user off). Frontend nav links check all three via shared Inertia props (`app_settings.*`, `customer.*`, `user_settings.*`) so a user never sees a link that 404s.

## Command design system

The app's UI is built on the **Command** design system — a token-driven set of Vue primitives at `resources/js/Components/Command/` styled via CSS variables in `resources/css/tokens.css`. All new UI should compose these primitives; reach for Tailwind only for layout (grid, flex, spacing) and for legacy PrimeVue escape hatches listed below.

### Tokens

Themes, accents, and densities are switched by setting `data-theme`, `data-accent`, `data-density` on `<html>`. This is done by `useTweaks()` (see `resources/js/composables/useTweaks.ts`) and persisted to localStorage. The pre-hydration script in `resources/views/app.blade.php` applies the saved tokens before Vue hydrates so there's no theme flash.

- Themes: `dark` (default) · `hc` (high-contrast) · `light`
- Accents: `cobalt` (default) · `emerald` · `amber` · `violet`
- Densities: `compact` · `comfortable` (default) · `relaxed`

Key tokens (always use `var(...)`, never hardcoded colors):
- Surface: `--bg`, `--bg2`, `--panel`, `--panel2`, `--border`
- Text: `--fg`, `--fg-dim`, `--fg-mute`
- Accent: `--accent`, `--accent-soft`, `--accent-border`
- Semantic: `--success`, `--warning`, `--danger`
- Layout: `--pad-page`, `--pad-row`, `--radius-card`, `--radius-control`, `--radius-chip`
- Typography: `--font-ui`, `--font-mono`

Utility classes: `.cmd-shell` (root page), `.cmd-card` (panel with border + radius), `.cmd-mono` (JetBrains Mono + tabular numbers), `.cmd-uc` (uppercase + letter-spacing), `.cmd-skeleton` (shimmer loader).

### Primitives (all in `resources/js/Components/Command/`)

| Component | Purpose |
|---|---|
| `Dialog.vue` | Modal dialog with backdrop, Esc, focus trap, header/body/footer slots. **Use for every dialog.** |
| `Button.vue` | Variants `primary` / `ghost` / `danger`, sizes `sm` / `md`, `loading`, `full-width`, leading `#icon` slot |
| `Field.vue` | Labeled text input. `type`, `error`, `autofocus`, `numeric` modifiers |
| `Select.vue` | Labeled native `<select>` wrapper |
| `Toggle.vue` | 32×18 pill switch for booleans |
| `Icon.vue` | 16×16 inline SVG set, 1.5 stroke, currentColor |
| `PublicTopbar.vue` | Unauthenticated top bar (marketing/error pages) |
| `Rail.vue`, `Topbar.vue`, `CommandLayout.vue` | Authenticated app shell — don't reimplement, use `defineOptions({ layout: CommandLayout })` |
| `CommandPalette.vue` | ⌘K navigation palette |
| `DataTable.vue` | Sortable/searchable admin tables |
| `Dot.vue`, `Counter.vue`, `Kbd.vue`, `Skeleton.vue`, `ToastStack.vue` | Supporting pieces |

### Building a new dialog

```vue
<script setup lang="ts">
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import Button from '@/Components/Command/Button.vue';
import { ref } from 'vue';
const open = ref(false);
const name = ref('');
</script>
<template>
  <CommandDialog v-model:visible="open" title="Rename folder" width="380px">
    <Field v-model="name" label="Name" autofocus />
    <template #footer>
      <Button variant="ghost" size="sm" @click="open = false">Cancel</Button>
      <Button variant="primary" size="sm">Save</Button>
    </template>
  </CommandDialog>
</template>
```

Use `data-autofocus` on any element inside the dialog to override the default autofocus target.

### Theme / PrimeVue escape hatches

PrimeVue is still loaded for a handful of components that have no Command equivalent: `DataTable`, `MultiSelect`, `ConfirmDialog`, `Password` (toggle-mask input). Dark-mode overrides for these live in `app.css` and are kept in sync via the `.dark` class toggled by `useTweaks()`.

- **Never use `window.confirm()` or `window.prompt()`** — use `<ConfirmDialog>` + `useConfirm()` for yes/no.
- **Group your ConfirmDialogs** so they don't cross-fire: a page with multiple confirms should name a group (`group="files"`) and pass it to every `confirm.require(...)` call.
- **For any custom dialog, use `CommandDialog`** — NOT PrimeVue `Dialog`. PrimeVue Dialog's palette drifts from the Command tokens and requires brittle `:pt` overrides.

### Flash & toasts

Flash → PrimeVue toast via `useFlashToast.ts`. Backend: `->with('success', '...')`. For in-page toasts inside Command flows, prefer `useCommandToasts()` (top-right, token-styled).

## Settings System

User preferences in `user_settings.settings` (JSONB): `locale`, `dark_mode`, notification prefs, `files_enabled`, `storage_quota_bytes`, `last_customer_slug`, etc. Flow: localStorage → Inertia props override → debounced `PATCH /settings`.

**Extending `UserSetting::$defaults`**: add the key + default, also add it to the `UserSettingsShape` PHPDoc array shape at the top of `UserSetting.php` (PHPStan reads this). No migration needed — values are merged on read via `$user->settings()->resolved()`.

## Personal File System (optional, off by default)

Per-user folders + previews + quotas at `/files`, behind three feature flags (see Layered feature flags above). Backed by `spatie/laravel-medialibrary`.

- **Storage**: `FileItem` tree (self-referential `parent_id`), media collection `file`, conversions `thumb/medium/large/xlarge`. Videos also get a `video_preview` (poster frame) + `video_web` (H.264 MP4). PDFs/Office docs get a `doc_preview` image via Gotenberg.
- **Conversions run async** (medialibrary queues are on by default). Broadcast `FileItemUpdated` on `private:App.Models.User.{id}` when a conversion finishes; `Pages/Files/Index.vue` patches the in-grid item via `liveItems` reactive map so thumbnails swap in place.
- **Processing spinner**: the grid tile overlays a `pi-spinner` when `item.video_processing`. Goes away the moment the MP4 preview lands (or the source was already web-compatible).
- **Queue worker gotcha**: after `composer require` a new package, the running Horizon workers have stale autoloaders and will fail with "Class X not found". Run `php artisan horizon:terminate` (Supervisor respawns with the fresh autoloader). `queue:restart` alone only tells workers to exit after the current job.
- **Quota failures must not render as HTML error pages.** `EnsureStorageAvailable` middleware throws `ValidationException::withMessages([...])` for Inertia / JSON / XHR requests, and falls back to `redirect()->back()->withErrors(...)` for plain form posts. UploadDialog reads the resulting errors via `onError`.
- **External services**: Gotenberg container (office → PDF conversion), ffmpeg + `pbmedia/laravel-ffmpeg` (video probe + transcode). Both declared in `docker-compose.yml` / `Dockerfile`.
- **Sharing**: `/share/{token}` public pages. Full shares live in `file_shares` with optional password + expiry (capped at `AppSetting::max_share_days`). Quick single-file links use Laravel's signed URLs (no DB row).
- **Soft-delete + trash**: `FileItem` uses `SoftDeletes`; the `deleting` observer cascades to children (skipped when `isForceDeleting()`), and `FileTrashController::restore` cascades back by matching the shared `deleted_at` timestamp (sub-second window). `PurgeTrashedFileItems` scheduled command hard-deletes anything older than 30 days.

## Observability

- **Sentry** — `SENTRY_LARAVEL_DSN` in `.env`
- **Backups** — `spatie/laravel-backup`, schedule in `routes/console.php`, UI at `/admin/backups`
- **Log Viewer** — `/log-viewer` (Admin gate)
- **Impersonation** — `lab404/laravel-impersonate`, amber banner, activity logged. Echo's `App.Models.User.{id}` private channel authorizes against `auth()->user()->id`, which during impersonation is the impersonated user — so broadcasts reach the right tab without any special channel plumbing
- **Static analysis** — Larastan level 5: `make stan`
- **Pre-commit hooks** — Husky + lint-staged (pint + vue-tsc)

## Docker & Dev

`make init` → bootstrap `.env`. `make build` → start containers. `make rebuild` → full reset with `migrate:fresh --seed`.

### Per-machine overrides

`docker-compose.override.yml` is gitignored and auto-loaded by `docker compose`. Copy `docker-compose.override.yml.example` to opt in — useful on macOS + OrbStack to publish the app container under a stable hostname (e.g. `ekstremedia-kit.local`) via the `dev.orbstack.domains` label. A `.local` suffix resolves via macOS mDNS without additional setup; custom TLDs like `.test` need OrbStack's system DNS integration enabled. Match `APP_URL` / `VITE_DEV_SERVER_HOST` / `VITE_REVERB_HOST` in `.env` to whatever hostname you pick.

Dev login: `DEV_EASY_LOGIN_ENABLED=true` shows shortcut on login page (local/test only).

Websockets: Reverb runs in Supervisor. `VITE_REVERB_HOST` = public hostname for browser WS connection.

## Laravel Boost MCP

`.mcp.json.example` → copy to `.mcp.json`, replace `<PROJECT_ROOT>` with your clone path. Runs inside the `app` container via `docker compose exec -T app php artisan boost:mcp`.

**Agents MUST prefer Boost tools over manual alternatives** whenever they apply. Boost gives you first-class, schema-aware access to this app's state — reaching for raw shell, `grep`, or guessed URLs when Boost has a tool is a smell.

Use Boost for these tasks (non-exhaustive):

- **Database inspection** → `database-query` (read-only SQL), `database-schema` (tables/columns/indexes), `database-connections`. Do not `psql` the container or read migration files to guess schema.
- **Logs & errors** → `read-log-entries` for `storage/logs/laravel.log` (handles multi-line PSR-3 properly, beats `tail`/`cat`), `last-error` for the most recent backend exception, `browser-logs` for JS console output.
- **Documentation lookup** → `search-docs` for Laravel ecosystem docs pinned to this project's package versions. Use this before web search for anything Laravel / Inertia / Pest / Pulse / Horizon / Reverb / Scout / Livewire / Tailwind.
- **Project facts** → `application-info` (PHP/Laravel versions, installed packages, Eloquent models). `get-absolute-url` for URLs from paths or named routes.
- **Browser automation** → the Chrome DevTools MCP tools (`mcp__chrome-devtools__*`) to reproduce UI bugs, capture console messages, and script clicks / uploads against a running dev build.

Caveats:
- Boost's MCP set here does NOT include a PHP evaluator / `tinker` tool. For dispatching events, running jobs, or mutating state ad-hoc, `docker compose exec app php artisan tinker --execute='…'` is still the right call.
- `database-query` is read-only — it rejects writes. Use `tinker` or a proper migration for mutations.

## Maintenance Rules

- Keep behavior generic — no domain-specific nouns or seed data
- Environment-driven configuration over hardcoded values
- New UI strings → update both `en.ts` and `no.ts` (and both `lang/en/*.php` + `lang/no/*.php` for backend messages)
- New settings → update `UserSetting::$defaults` + the `UserSettingsShape` PHPDoc + TypeScript interface
- New tenant column → add to `Tenant::getCustomColumns()` + cast + factory
- New central-DB model → pin it with `getConnectionName()` (see Tenancy gotchas)
- Verify both host and container test runs
- `APP_TIMEZONE` in `.env` controls backend time — defaults to UTC. Set to local (e.g. `Europe/Oslo`) in dev if log timestamps matter

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/pulse (PULSE) - v1
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- laravel/scout (SCOUT) - v11
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- laravel-echo (ECHO) - v2
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `fortify-development` — ACTIVATE when the user works on authentication in Laravel. This includes login, registration, password reset, email verification, two-factor authentication (2FA/TOTP/QR codes/recovery codes), profile updates, password confirmation, or any auth-related routes and controllers. Activate when the user mentions Fortify, auth, authentication, login, register, signup, forgot password, verify email, 2FA, or references app/Actions/Fortify/, CreateNewUser, UpdateUserProfileInformation, FortifyServiceProvider, config/fortify.php, or auth guards. Fortify is the frontend-agnostic authentication backend for Laravel that registers all auth routes and controllers. Also activate when building SPA or headless authentication, customizing login redirects, overriding response contracts like LoginResponse, or configuring login throttling. Do NOT activate for Laravel Passport (OAuth2 API tokens), Socialite (OAuth social login), or non-auth Laravel features.
- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `configuring-horizon` — Use this skill whenever the user mentions Horizon by name in a Laravel context. Covers the full Horizon lifecycle: installing Horizon (horizon:install, Sail setup), configuring config/horizon.php (supervisor blocks, queue assignments, balancing strategies, minProcesses/maxProcesses), fixing the dashboard (authorization via Gate::define viewHorizon, blank metrics, horizon:snapshot scheduling), and troubleshooting production issues (worker crashes, timeout chain ordering, LongWaitDetected notifications, waits config). Also covers job tagging and silencing. Do not use for generic Laravel queues without Horizon, SQS or database drivers, standalone Redis setup, Linux supervisord, Telescope, or job batching.
- `pulse-development` — Handles Laravel Pulse setup, configuration, and custom card development. Activates when installing Pulse; configuring the dashboard or authorization gate; setting up recorders and filtering; building custom Livewire cards; optimizing with Redis ingest or sampling; or when the user mentions /pulse, pulse:check, pulse:work, Pulse::record(), or application monitoring.
- `scout-development` — Develops full-text search with Laravel Scout. Activates when installing or configuring Scout; choosing a search engine (Algolia, Meilisearch, Typesense, Database, Collection); adding the Searchable trait to models; customizing toSearchableArray or searchableAs; importing or flushing search indexes; writing search queries with where clauses, pagination, or soft deletes; configuring index settings; troubleshooting search results; or when the user mentions Scout, full-text search, search indexing, or search engines in a Laravel project. Make sure to use this skill whenever the user works with search functionality in Laravel, even if they don't explicitly mention Scout.
- `socialite-development` — Manages OAuth social authentication with Laravel Socialite. Activate when adding social login providers; configuring OAuth redirect/callback flows; retrieving authenticated user details; customizing scopes or parameters; setting up community providers; testing with Socialite fakes; or when the user mentions social login, OAuth, Socialite, or third-party authentication.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: test()/it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `inertia-vue-development` — Develops Inertia.js v3 Vue client-side applications. Activates when creating Vue pages, forms, or navigation; using <Link>, <Form>, useForm, useHttp, setLayoutProps, or router; working with deferred props, prefetching, optimistic updates, instant visits, or polling; or when user mentions Vue with Inertia, Vue pages, Vue forms, or Vue navigation.
- `echo-development` — Develops real-time broadcasting with Laravel Echo. Activates when setting up broadcasting (Reverb, Pusher, Ably); creating ShouldBroadcast events; defining broadcast channels (public, private, presence, encrypted); authorizing channels; configuring Echo; listening for events; implementing client events (whisper); setting up model broadcasting; broadcasting notifications; or when the user mentions broadcasting, Echo, WebSockets, real-time events, Reverb, or presence channels.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.
- `laravel-backup` — Configure and extend spatie/laravel-backup for database and file backups, cleanup strategies, health monitoring, and notifications. Activates when working with backup configuration, scheduling backups, creating custom cleanup strategies or health checks, customizing notifications, or when the user mentions backups, backup monitoring, backup cleanup, or spatie/laravel-backup.
- `medialibrary-development` — Build and work with spatie/laravel-medialibrary features including associating files with Eloquent models, defining media collections and conversions, generating responsive images, and retrieving media URLs and paths.
- `laravel-permission-development` — Build and work with Spatie Laravel Permission features, including roles, permissions, middleware, policies, teams, and Blade directives.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

=== spatie/laravel-activitylog rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

=== spatie/laravel-medialibrary rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
