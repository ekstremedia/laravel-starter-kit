<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0d10">

    {{-- Installability / home-screen icons. Harmless when the PWA is off; the
         manifest is only linked when enabled (otherwise it 404s). --}}
    <link rel="icon" href="/icons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/icons/source.svg">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180x180.png">
    @if (config('pwa.enabled'))
        <link rel="manifest" href="/build/manifest.webmanifest">
    @endif

    <title inertia>{{ config('app.name', 'Laravel Starter Kit') }}</title>

    <script>
        (function() {
            var key = @json(config('app.storage_key'));
            var root = document.documentElement;
            try {
                var s = JSON.parse(localStorage.getItem(key) || '{}');
                // Command tokens are driven by data-* attributes — set pre-hydrate
                // so first paint lands on the right theme. If `theme` is missing
                // but the legacy `dark_mode: false` flag is set, respect it and
                // resolve to 'light' (previously this branch silently fell back
                // to dark for users still on the old flag).
                var theme = s.theme === 'light' || s.theme === 'hc' || s.theme === 'dark'
                    ? s.theme
                    : (s.dark_mode === false ? 'light' : 'dark');
                root.setAttribute('data-theme', theme);
                root.classList.toggle('dark', theme !== 'light'); // hc is also dark-ish for PrimeVue
                root.setAttribute('data-accent', ['emerald', 'amber', 'violet'].indexOf(s.accent) >= 0 ? s.accent : 'cobalt');
                root.setAttribute('data-density', ['compact', 'relaxed'].indexOf(s.density) >= 0 ? s.density : 'comfortable');
                root.style.setProperty('--rail-w', s.rail_expanded === true ? '180px' : '52px');
            } catch(e) {
                root.classList.add('dark');
                root.setAttribute('data-theme', 'dark');
                root.setAttribute('data-accent', 'cobalt');
                root.setAttribute('data-density', 'comfortable');
                root.style.setProperty('--rail-w', '52px');
            }
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600|instrument-sans:400,500,600,700" rel="stylesheet" />

    @if (! app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @endif
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
