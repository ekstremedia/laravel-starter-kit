<?php

/**
 * Assets — the demo file-owning entity. This whole module (app/Domains/Assets,
 * this config, the migration, routes, and resources/js/Pages/Assets) is meant
 * to be deleted wholesale when you don't want it, or copied as the template
 * for a real entity (Vehicle, Medicine, Building…).
 */
return [
    // Master switch for the demo Assets feature. Set ASSETS_ENABLED=false to
    // hide the nav entry and 404 the routes without removing the code.
    'enabled' => (bool) env('ASSETS_ENABLED', true),
];
