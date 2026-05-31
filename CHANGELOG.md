# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims to
follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-05-31

Repository polish for the public template — no application code changes.

### Added
- README badges (CI, latest release, license, PHP, Laravel), a screenshot gallery,
  and a **Use this template** quick-start section.
- App screenshots under `docs/screenshots/` (welcome, dashboard, admin, system).
- Community-health files: `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`,
  issue forms, and a pull-request template.
- Dependabot configuration for Composer, npm, and GitHub Actions (weekly).
- This changelog.

## [1.0.0] - 2026-05-31

Initial public release — a production-shaped, fully Dockerized starter kit.

### Added
- Laravel 13 · PHP 8.5 · Inertia v3 + Vue 3 + TypeScript, with PostgreSQL 17,
  Redis 7, and Mailpit. php-fpm, nginx, Vite, Reverb, Horizon, Pulse, and the
  scheduler run under supervisor.
- Authentication via Fortify (login, register, email verification, password reset,
  TOTP 2FA + recovery codes), Sanctum, and Spatie Permission (Admin / Editor / User).
- Admin area: users, roles & permissions, SMTP settings + test send, storage &
  quotas, backups, system health, and activity monitoring (Horizon · Pulse ·
  log-viewer).
- File manager (personal + company), share links, and trash; optional workspaces,
  an equipment demo module, chat, Inertia SSR, and an installable PWA.
- Pest + Vitest test suites, Pint, Larastan, Husky pre-commit hooks, and GitHub
  Actions CI.

[Unreleased]: https://github.com/ekstremedia/laravel-starter-kit/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/ekstremedia/laravel-starter-kit/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/ekstremedia/laravel-starter-kit/releases/tag/v1.0.0
