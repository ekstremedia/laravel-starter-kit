# Contributing

Thanks for taking the time to contribute! This is a starter kit — most people will **use this template** to start their own app, but improvements to the kit itself (bug fixes, docs, new wiring) are very welcome.

## Using vs. contributing

- **Building your own app?** Click **Use this template** on GitHub, then follow the [Quick start](README.md#quick-start). You don't need this file.
- **Improving the kit?** Read on.

## Development setup

Everything runs in Docker — no host PHP, Node, or Postgres required.

```bash
make build      # build the image, start the stack, migrate + seed
make shell      # open a shell in the app container
make logs       # tail app logs
```

Run `make help` for the full list of targets. Artisan, Composer, Pest, and npm all run **inside** the `app` container (use `make shell` or `docker compose exec app …`), never on the host.

## Before you open a PR

The full CI suite must pass. Run it locally:

```bash
make test-all   # Pint, Larastan, Pest (parallel), vue-tsc, Vitest
```

Or individually while iterating:

```bash
make pint       # format PHP (Laravel Pint)
make stan       # static analysis (Larastan)
make test       # Pest (SQLite in-memory)
make test-js    # Vitest
```

A Husky pre-commit hook runs the relevant checks automatically; CI runs the same suites on every push and PR to `main`.

## Conventions

- **Code style** — PHP is formatted by Pint; the frontend by the project's ESLint/Prettier setup. Match the surrounding code.
- **Architecture** — backend code is organised into domain modules under `app/Domains/*` (Auth, Users, Access, Files, Chat, Workspaces, Notifications, Settings, Operations). Conventions live in [`AGENTS.md`](AGENTS.md) — please read it before adding new code.
- **Frontend** — Inertia + Vue 3 + TypeScript; see [`docs/frontend-architecture.md`](docs/frontend-architecture.md).
- **Commits & PRs** — keep changes focused, write a clear description, and target `main`. Add or update tests for behaviour changes, and update docs when you change setup or features.

## Reporting bugs & requesting features

Open an issue using the templates. For **security vulnerabilities**, do **not** open a public issue — see [SECURITY.md](SECURITY.md).
