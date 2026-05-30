<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// Always clean up the generated files, even if an assertion fails.
afterEach(function () {
    File::deleteDirectory(app_path('Domains/Gadget'));
    File::deleteDirectory(resource_path('js/Pages/Gadgets'));
    @unlink(config_path('gadget.php'));
    @unlink(database_path('factories/GadgetFactory.php'));
    @unlink(database_path('seeders/GadgetSeeder.php'));
    @unlink(lang_path('en/gadget.php'));
    @unlink(lang_path('no/gadget.php'));
    foreach (glob(database_path('migrations/*_create_gadgets_table.php')) ?: [] as $f) {
        @unlink($f);
    }
});

it('scaffolds a module with its tokens replaced', function () {
    expect(Artisan::call('make:module', ['name' => 'Gadget']))->toBe(0);

    $model = app_path('Domains/Gadget/Models/Gadget.php');
    expect(File::exists($model))->toBeTrue();

    $contents = File::get($model);
    expect($contents)->toContain('class Gadget extends Model implements FileOwner')
        ->and($contents)->toContain("protected \$table = 'gadgets';")
        ->and($contents)->toContain("->useLogName('gadget')")
        ->and($contents)->not->toContain('{{ class }}')
        ->and($contents)->not->toContain('{{ key }}');

    expect(File::exists(config_path('gadget.php')))->toBeTrue()
        ->and(File::exists(app_path('Domains/Gadget/Http/Controllers/GadgetController.php')))->toBeTrue()
        ->and(File::exists(resource_path('js/Pages/Gadgets/Index.vue')))->toBeTrue()
        ->and(File::exists(resource_path('js/Pages/Gadgets/Show.vue')))->toBeTrue()
        ->and(count(glob(database_path('migrations/*_create_gadgets_table.php')) ?: []))->toBe(1);
});

it('refuses reserved module names', function () {
    expect(Artisan::call('make:module', ['name' => 'Equipment']))->toBe(1);
});
