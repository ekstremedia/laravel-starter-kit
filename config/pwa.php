<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Progressive Web App
    |--------------------------------------------------------------------------
    |
    | Opt-in PWA support. When enabled, `npm run build` (with VITE_PWA_ENABLED
    | also set so Vite wires vite-plugin-pwa) emits a web manifest + service
    | worker, and the root template links the manifest. Reuses the same env flag
    | the Vite build reads so the two never drift.
    |
    */

    'enabled' => filter_var(env('VITE_PWA_ENABLED', false), FILTER_VALIDATE_BOOL),

];
