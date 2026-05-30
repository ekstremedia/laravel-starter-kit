# Frontend architecture

Vue 3 + TypeScript rendered through **Inertia.js v3**. Laravel owns routing, auth and controllers; each route returns `Inertia::render('Page', props)` and Vue renders `resources/js/Pages/Page.vue`. There is no client-side router and no separate API layer for page data.

## Entry & build

- `resources/js/app.ts` — client entry: `createInertiaApp`, plugin registration (Pinia, i18n, PrimeVue/Aura, Toast/Confirm), guarded PWA SW registration.
- `resources/js/ssr.ts` — SSR entry, mirrors `app.ts` (see SSR below).
- `vite.config.ts` — laravel-vite-plugin (client + opt-in SSR input), Vue, Tailwind, opt-in vite-plugin-pwa, and a small `manualChunks` map.

## Code-splitting & performance

The app should load fast and stay lean. The levers in place:

- **Per-page splitting (default).** `app.ts` resolves pages with a lazy `import.meta.glob('./Pages/**/*.vue')` (no `{ eager: true }`), so each page is its own chunk fetched on demand. Keep it that way.
- **Lazy heavy libraries.** Anything large that only some pages need is loaded with a dynamic `import()` so it never enters the entry bundle:
  - ApexCharts → `Components/Command/LazyChart.vue` (`defineAsyncComponent`). Use `<LazyChart>` instead of registering ApexCharts globally.
  - Leaflet / markdown-it → dynamic `import()` inside the file/preview components.
- **Stable vendor chunks.** `manualChunks` splits `vue`, `@inertiajs`, `primevue`, `vue-i18n` so app-code changes don't bust their long-term cache. Don't add dynamically-imported libs here — that would pull them back onto the critical path.
- **Inertia v3 latency features** — reach for these where they help:
  - `<Link prefetch>` on high-traffic navigation (e.g. the rail) for instant transitions.
  - `Inertia::defer(fn () => ...)` for props not needed at first paint (e.g. the shared `available_workspaces`, or heavy dashboard data). **Never** defer first-paint-critical shared props (`auth.user`, `current_workspace`, `locale`, `user_settings`).
  - `<WhenVisible>` to load deferred data when a section scrolls into view.
- History encryption is on by default (`config/inertia.php`) so the back button can't reveal data after logout.

Verify the result: `npm run build` then check `public/build/manifest.json` — heavy libs should be their own chunks, not part of `app-*.js`.

## State management (Pinia)

Pinia is registered in `app.ts` (with `pinia-plugin-persistedstate`, opt-in per store via `persist`). Stores live in `resources/js/stores/`:

- `notifications` — recent-notifications feed, fed by the layout's single user-channel subscription.

Use Pinia for **genuinely shared/new** state. The pre-existing module-singleton composables (`useTweaks`, `useSettings`, `useUnreadCounts`, `useCommandToasts`, …) work and are SSR-aware — leave them; don't migrate for its own sake. Stores must not touch `localStorage`/`window` at module scope (SSR safety).

## SSR (opt-in, off by default)

Full Inertia SSR is wired but disabled. To enable:

1. `INERTIA_SSR_ENABLED=true`
2. `npm run build:ssr` (builds the client **and** the `bootstrap/ssr` bundle)
3. `supervisorctl start inertia-ssr` (the supervisor program ships `autostart=false`)

`resources/js/ssr.ts` mirrors `app.ts`'s plugin stack but **never imports `./bootstrap`** (which boots Echo/Pusher and touches `window`) and registers Pinia without the persistence plugin.

**SSR safety rule:** any `window` / `document` / `localStorage` / `window.Echo` access must be inside `onMounted`/`onBeforeUnmount`, or behind a `typeof … === 'undefined'` guard — never at module or `setup` scope. See `useSettings`, `useUserChannel` and the `viewMode` ref-initializers in the file pages for the idiom. The pre-hydration theme script in `resources/views/app.blade.php` sets `<html>` attributes outside the Vue root, so it doesn't cause hydration mismatches.

Defaults are kept `false` deliberately: a stale "enabled with no bundle" config silently falls back to CSR, which is the trap this avoids.

## PWA (opt-in, off by default)

`VITE_PWA_ENABLED=true` (read by both Vite and `config/pwa.php`) + a rebuild emits a web manifest and a **conservative** Workbox service worker:

- Precaches built JS/CSS/fonts and the static `public/offline.html` shell.
- `offline.html` is the navigation fallback, with an `admin`/`api`/`broadcasting`/`login`/`logout`/`storage` denylist.
- **Never** caches HTML, JSON, `/storage` media, or POST — no authenticated data is ever stored.
- `registerType: 'prompt'` + manual guarded registration in `app.ts` (prod + flag + browser only); the SW claims root scope via the `Service-Worker-Allowed: /` header on `/build/sw.js` (in `docker/nginx.conf`).

`useInstallPrompt` exposes the `beforeinstallprompt` flow for an install button. Replace `resources/icons/source.svg` and run `npm run pwa:icons` to brand the app icons.

## Localization, design system, testing

- Every visible string uses `t('key')` (vue-i18n); update **both** `resources/js/i18n/{en,no}.ts`.
- UI composes **Command** primitives (`resources/js/Components/Command/`) + CSS-var tokens — see the design-system notes in `agents.md`.
- Components/composables are unit-tested with Vitest (`tests/frontend/**`); pages and broadcasts with Pest. Run the lot with `make test-all`.
