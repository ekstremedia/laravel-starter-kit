# Laravel Starter Kit
 
A Laravel 13 + Inertia/Vue starter with the usual production pieces already wired up — auth with 2FA, an admin dashboard, roles & permissions, queues, broadcasting, media uploads, activity log, backups, observability, and tests — all running in Docker.

## Stack

- **Laravel 13 · PHP 8.4** · PostgreSQL 17 · Redis 7 · Mailpit
- **Inertia.js v3 + Vue 3 + TypeScript** · Tailwind v4 · PrimeVue v4
- **Docker** — php-fpm, nginx, Vite, Reverb, Horizon, Pulse, and the scheduler under supervisor
- **Fortify** (login, register, email verification, password reset, TOTP 2FA + recovery codes) · **Sanctum** · **Spatie Permission** (`Admin` / `Editor` / `User` seeded)
- **Spatie** Medialibrary · Activitylog · Backup · Pulse · Horizon · Sentry · opcodesio log-viewer · lab404 impersonate
- **Pest 4** + **Vitest 4** tests · Pint · Larastan · Husky pre-commit · GitHub Actions CI

Backend code is organised into domain modules under `app/Domains/*` (Auth, Users, Access, Files, Chat, Workspaces, Notifications, Settings, Operations). Conventions live in `AGENTS.md`.

## Quick start

Requires **Docker** only — no host PHP, Node, Postgres, or `/etc/hosts` edits.

```bash
make build    # creates .env, builds the image, starts the stack, migrates + seeds
```

Open **http://localhost:8120** and sign in as the seeded admin (`admin@example.test` / `password`). Captured email is at **http://localhost:8126** (Mailpit). For a one-click local login button on `/login`, set `DEV_EASY_LOGIN_ENABLED=true`.

`make help` lists every target. The common ones:

```bash
make shell      # shell into the app container
make test       # Pest
make test-all   # Pest + Larastan + typecheck + Vitest
make fresh      # migrate:fresh --seed (local only)
make logs       # tail app logs
```

`destroy`, `fresh`, and `rebuild` refuse to run unless `APP_ENV=local`.

## Configuration

`.env.example` is tuned for a zero-config localhost run. Override in `.env` when you need to:

- **Different port** — set `APP_HOST_PORT` and the matching port in `APP_URL`.
- **Run a second copy alongside this one** — also give it free `VITE_HOST_PORT`, `REVERB_HOST_PORT`, and `MAILPIT_HOST_PORT` values.
- **Custom hostname** (e.g. `app.test`) — point `APP_URL`, `VITE_DEV_SERVER_HOST`, and `VITE_REVERB_HOST` at it and add it to `/etc/hosts`.
- **Guided setup** — `make init` prompts for app name, host port, URL, DB creds, and the seeded admin, then writes `.env`. Optional; `make build` works without it.

## Admin

Everything under `/admin/*` is gated by `role:Admin`.

| Route | What's there |
| --- | --- |
| `/admin` | Dashboard — stats, charts, recent activity |
| `/admin/users` | Users CRUD, roles, quotas, impersonate |
| `/admin/workspaces` | Workspace CRUD — when `WORKSPACES_ENABLED=true` |
| `/admin/roles` · `/admin/permissions` | Roles + granular permissions |
| `/admin/mail` | SMTP settings (encrypted) + test send |
| `/admin/storage` | Per-user storage usage and quotas |
| `/admin/backups` | Run/clean backups, download archives, prepare restores |
| `/admin/system` | Queue / Reverb / Redis health + runtime snapshot |
| `/admin/monitoring` | Activity log + embedded Horizon · Pulse · log-viewer |

Users self-manage profile, password, and 2FA at `/profile`.

## Optional features

- **Multi-tenancy (workspaces)** — optional, **single-database row-level** isolation (no schema/DB per workspace). Every workspace-scoped table carries a `workspace_id` and the `BelongsToWorkspace` global scope auto-filters queries to the current workspace, so you can't leak across workspaces by forgetting a `where`. The current workspace is held by the `App\Domains\Workspaces\Support\WorkspaceContext` resolver (set per request by `ResolveWorkspace`).
  - `WORKSPACES_ENABLED=true` → users can belong to many workspaces with per-workspace roles; routes live under `/w/{workspace}/*`; the `/admin/workspaces` UI + workspace switcher appear.
  - `WORKSPACES_ENABLED=false` → behaves like a normal single-workspace Laravel app: workspace routes mount at the **root** (no `/w/` prefix), no switcher/picker.
  - `WORKSPACES_REGISTRATION_MODE=create_own` makes each sign-up create its own workspace (becoming admin) and invite others; `join_default` (default) auto-joins the shared default workspace.
  - Admins invite by email (`/members` → *Invite by email*); invitees accept via a tokenised link (`/invitations/{token}`), registering or logging in along the way. See **[docs/adding-a-workspace-entity.md](docs/adding-a-workspace-entity.md)** to add your own workspace-scoped entity (Car, Medicine, …). Model: `App\Domains\Workspaces\Models\Workspace`.
- **Table prefix** — set `DB_TABLE_PREFIX=acme_` and re-migrate to namespace every core table. Only Eloquent / Query Builder / Schema queries inherit it; raw SQL must call `DB::getTablePrefix()`.

## Testing

Pest lives in `tests/`, Vitest in `tests/frontend/`. `make test` runs Pest on SQLite in-memory; `make test-all` runs the full suite (Pint, Larastan, vue-tsc, Vitest). CI runs both suites against Postgres + Redis.

## Customize first

- Welcome page copy — `resources/js/i18n/{en,no}.ts` (`welcome.*` keys)
- Roles & permissions — `database/seeders/RoleAndPermissionSeeder.php`
- Branding — `public/`, `resources/js/Pages/Welcome.vue`, `APP_NAME`
- Env vars — `.env.example` (every setting is commented)
</content>
