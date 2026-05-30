<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// Generate into a throwaway directory (via --base) rather than the live config/
// + app/ tree. Writing real config/<key>.php into the project races parallel
// test workers — their LoadConfiguration globs config/*.php and chokes on a
// transient file. A temp base keeps generation fully isolated.
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/modgen_'.Str::random(12);
    File::ensureDirectoryExists($this->base);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

it('scaffolds a full file-owning module with its tokens replaced', function () {
    expect(Artisan::call('make:module', ['name' => 'Gadget', '--base' => $this->base]))->toBe(0);

    $model = "{$this->base}/app/Domains/Gadget/Models/Gadget.php";
    $widget = "{$this->base}/app/Domains/Gadget/Dashboard/GadgetDashboardWidget.php";
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

    expect(File::exists("{$this->base}/config/gadget.php"))->toBeTrue()
        ->and(File::exists("{$this->base}/app/Domains/Gadget/Http/Controllers/GadgetController.php"))->toBeTrue()
        ->and(File::exists("{$this->base}/resources/js/Pages/Gadgets/Index.vue"))->toBeTrue()
        ->and(File::exists("{$this->base}/resources/js/Pages/Gadgets/Show.vue"))->toBeTrue()
        ->and(count(glob("{$this->base}/database/migrations/*_create_gadgets_table.php") ?: []))->toBe(1);
});

it('scaffolds a lean module with --no-files and --no-log', function () {
    expect(Artisan::call('make:module', ['name' => 'Gizmo', '--no-files' => true, '--no-log' => true, '--base' => $this->base]))->toBe(0);

    $model = File::get("{$this->base}/app/Domains/Gizmo/Models/Gizmo.php");
    expect($model)->toContain('class Gizmo extends Model')
        ->and($model)->not->toContain('implements FileOwner')
        ->and($model)->not->toContain('HasFiles')
        ->and($model)->not->toContain('LogsActivity')
        ->and($model)->not->toContain('cover_file_item_id')
        ->and($model)->not->toContain('@files')
        ->and($model)->not->toContain('@log');

    $controller = File::get("{$this->base}/app/Domains/Gizmo/Http/Controllers/GizmoController.php");
    expect($controller)->not->toContain('bulkZip')
        ->and($controller)->not->toContain('setCover')
        ->and($controller)->not->toContain('private function activities')
        ->and($controller)->not->toContain('FileItem');

    $show = File::get("{$this->base}/resources/js/Pages/Gizmos/Show.vue");
    expect($show)->not->toContain('EntityFiles')
        ->and($show)->not->toContain('activityLabel')
        ->and($show)->not->toContain('@files')
        ->and($show)->not->toContain('@log');

    $migration = collect(glob("{$this->base}/database/migrations/*_create_gizmos_table.php") ?: [])->first();
    expect($migration)->not->toBeNull()
        ->and(File::get($migration))->not->toContain('cover_file_item_id');
});

it('refuses reserved module names', function () {
    expect(Artisan::call('make:module', ['name' => 'Equipment', '--base' => $this->base]))->toBe(1)
        ->and(Artisan::call('make:module', ['name' => 'EquipmentCategory', '--base' => $this->base]))->toBe(1);
});
