<?php

/**
 * EquipmentCategory — the reference *lean* module (no files, with Log) and the
 * owner of the demo Equipment relation. This config seeds the module's default
 * enabled state; at runtime the source of truth is the `modules` table (see
 * ModuleRegistry), toggled from /admin/modules. This flag is only read when a
 * module row does not yet exist (fresh install / before seeding) or as a
 * fallback when the registry can't be read.
 */
return [
    // Default enabled state for the EquipmentCategory module on a fresh install.
    'enabled' => (bool) env('EQUIPMENT_CATEGORY_ENABLED', true),
];
