<?php

use App\Domains\Equipment\Dashboard\EquipmentDashboardWidget;

/**
 * Per-user, customizable workspace dashboard. Each module may contribute a
 * widget implementing App\Domains\Modules\Contracts\DashboardWidget. Add the
 * widget class here; it shows when its module is enabled and the user hasn't
 * hidden it. (make:module appends the new module's widget automatically.)
 */
return [
    'widgets' => [
        EquipmentDashboardWidget::class,
    ],
];
