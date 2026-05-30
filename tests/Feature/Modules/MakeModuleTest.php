<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// Always clean up generated files, even if an assertion fails.
afterEach(function () {
    foreach (['Gadget' => 'gadget', 'Gizmo' => 'gizmo'] as $studly => $key) {
        $plural = $studly.'s';
        $table = $key.'s';
        File::deleteDirectory(app_path("Domains/{$studly}"));
        File::deleteDirectory(resource_path("js/Pages/{$plural}"));
        @unlink(config_path("{$key}.php"));
        @unlink(database_path("factories/{$studly}Factory.php"));
        @unlink(database_path("seeders/{$studly}Seeder.php"));
        @unlink(lang_path("en/{$key}.php"));
        @unlink(lang_path("no/{$key}.php"));
        foreach (glob(database_path("migrations/*_create_{$table}_table.php")) ?: [] as $f) {
            @unlink($f);
        }
    }
});

it('scaffolds a full file-owning module with its tokens replaced', function () {
    expect(Artisan::call('make:module', ['name' => 'Gadget']))->toBe(0);

    $model = app_path('Domains/Gadget/Models/Gadget.php');
    $widget = app_path('Domains/Gadget/Dashboard/GadgetDashboardWidget.php');
    expect(File::exists($model))->toBeTrue()
        ->and(File::exists($widget))->toBeTrue();

    $contents = File::get($model);
    expect($contents)->toContain('class Gadget extends Model implements FileOwner')
        ->and($contents)->toContain("protected \$table = 'gadgets';")
        ->and($contents)->toContain("->useLogName('gadget')")
        ->and($contents)->toContain('use HasFiles;')
        ->and($contents)->not->toContain('{{ class }}')
        ->and($contents)->not->toContain('{{ key }}')
        // No conditional-region markers must survive into the output.
        ->and($contents)->not->toContain('@files')
        ->and($contents)->not->toContain('@log');

    $widgetContents = File::get($widget);
    expect($widgetContents)->toContain('class GadgetDashboardWidget implements DashboardWidget')
        ->and($widgetContents)->not->toContain('{{ class }}');

    expect(File::exists(config_path('gadget.php')))->toBeTrue()
        ->and(File::exists(app_path('Domains/Gadget/Http/Controllers/GadgetController.php')))->toBeTrue()
        ->and(File::exists(resource_path('js/Pages/Gadgets/Index.vue')))->toBeTrue()
        ->and(File::exists(resource_path('js/Pages/Gadgets/Show.vue')))->toBeTrue()
        ->and(count(glob(database_path('migrations/*_create_gadgets_table.php')) ?: []))->toBe(1);
});

it('scaffolds a lean module with --no-files and --no-log', function () {
    expect(Artisan::call('make:module', ['name' => 'Gizmo', '--no-files' => true, '--no-log' => true]))->toBe(0);

    $model = File::get(app_path('Domains/Gizmo/Models/Gizmo.php'));
    expect($model)->toContain('class Gizmo extends Model')
        ->and($model)->not->toContain('implements FileOwner')
        ->and($model)->not->toContain('HasFiles')
        ->and($model)->not->toContain('LogsActivity')
        ->and($model)->not->toContain('cover_file_item_id')
        ->and($model)->not->toContain('@files')
        ->and($model)->not->toContain('@log');

    $controller = File::get(app_path('Domains/Gizmo/Http/Controllers/GizmoController.php'));
    expect($controller)->not->toContain('bulkZip')
        ->and($controller)->not->toContain('setCover')
        ->and($controller)->not->toContain('private function activities')
        ->and($controller)->not->toContain('FileItem');

    $show = File::get(resource_path('js/Pages/Gizmos/Show.vue'));
    expect($show)->not->toContain('EntityFiles')
        ->and($show)->not->toContain('activityLabel')
        ->and($show)->not->toContain('@files')
        ->and($show)->not->toContain('@log');

    $migration = collect(glob(database_path('migrations/*_create_gizmos_table.php')) ?: [])->first();
    expect($migration)->not->toBeNull()
        ->and(File::get($migration))->not->toContain('cover_file_item_id');
});

it('refuses reserved module names', function () {
    expect(Artisan::call('make:module', ['name' => 'Equipment']))->toBe(1)
        ->and(Artisan::call('make:module', ['name' => 'EquipmentCategory']))->toBe(1);
});
