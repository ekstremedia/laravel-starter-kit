<?php

declare(strict_types=1);

namespace App\Domains\Modules\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scaffolds a new file-owning, workspace-scoped module from the same bare bones
 * as the demo Equipment module: migration, model, controller (CRUD + files +
 * datatable + mass actions + cover + trash + export), factory, seeder, config,
 * a dashboard widget, and Vue Index/Show/Trash pages.
 *
 * Every module is different — this gives you a complete, working starting point
 * with one `name` field; add your own columns/fields from there. After
 * generating, follow the printed wiring checklist (a few one-liners).
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : Studly singular name, e.g. Car}';

    protected $description = 'Scaffold a new workspace-scoped, file-owning module (CRUD + datatable + files)';

    public function handle(): int
    {
        $studly = Str::studly($this->argument('name'));        // Car
        $key = Str::snake($studly);                            // car
        $pluralStudly = Str::pluralStudly($studly);            // Cars
        $table = Str::snake($pluralStudly);                    // cars
        $route = Str::kebab($pluralStudly);                    // cars (url + route-name segment)
        $label = Str::headline($studly);                       // Car
        $pluralLabel = Str::headline($pluralStudly);           // Cars

        if (in_array($key, ['user', 'workspace', 'equipment', 'module'], true)) {
            $this->error("'{$key}' is reserved or already exists. Choose another name.");

            return self::FAILURE;
        }

        $replacements = [
            '{{ class }}' => $studly,
            '{{ key }}' => $key,
            '{{ keyUpper }}' => Str::upper($key),
            '{{ table }}' => $table,
            '{{ route }}' => $route,
            '{{ pluralStudly }}' => $pluralStudly,
            '{{ label }}' => $label,
            '{{ pluralLabel }}' => $pluralLabel,
        ];

        $stubDir = base_path('stubs/module');
        $migrationTs = Carbon::now()->format('Y_m_d_His');

        /** @var array<string, string> $files stub => target path */
        $files = [
            'model.stub' => app_path("Domains/{$studly}/Models/{$studly}.php"),
            'controller.stub' => app_path("Domains/{$studly}/Http/Controllers/{$studly}Controller.php"),
            'widget.stub' => app_path("Domains/{$studly}/Dashboard/{$studly}DashboardWidget.php"),
            'config.stub' => config_path("{$key}.php"),
            'factory.stub' => database_path("factories/{$studly}Factory.php"),
            'seeder.stub' => database_path("seeders/{$studly}Seeder.php"),
            'migration.stub' => database_path("migrations/{$migrationTs}_create_{$table}_table.php"),
            'Index.vue.stub' => resource_path("js/Pages/{$pluralStudly}/Index.vue"),
            'Show.vue.stub' => resource_path("js/Pages/{$pluralStudly}/Show.vue"),
            'Trash.vue.stub' => resource_path("js/Pages/{$pluralStudly}/Trash.vue"),
            'lang.stub' => lang_path("en/{$key}.php"),
        ];

        foreach ($files as $stub => $target) {
            $stubPath = "{$stubDir}/{$stub}";
            if (! is_file($stubPath)) {
                $this->error("Missing stub: {$stubPath}");

                return self::FAILURE;
            }
            if (is_file($target)) {
                $this->warn("Skipped (exists): {$target}");

                continue;
            }

            if (! $this->writeFile($target, strtr((string) file_get_contents($stubPath), $replacements))) {
                return self::FAILURE;
            }
            $this->info('Created: '.str_replace(base_path().'/', '', $target));
        }

        // Norwegian lang file (same stub, dev translates).
        $noLang = lang_path("no/{$key}.php");
        if (! is_file($noLang)) {
            if (! $this->writeFile($noLang, strtr((string) file_get_contents("{$stubDir}/lang.stub"), $replacements))) {
                return self::FAILURE;
            }
            $this->info('Created: '.str_replace(base_path().'/', '', $noLang));
        }

        $this->printChecklist($studly, $key, $route, $pluralStudly, $label);

        return self::SUCCESS;
    }

    /**
     * Ensure the directory exists and write the file, surfacing any failure so
     * the command stops instead of leaving a half-generated module.
     */
    private function writeFile(string $target, string $contents): bool
    {
        File::ensureDirectoryExists(dirname($target));
        if (File::put($target, $contents) === false) {
            $this->error("Failed to write: {$target}");

            return false;
        }

        return true;
    }

    private function printChecklist(string $studly, string $key, string $route, string $pluralStudly, string $label): void
    {
        $ns = "App\\Domains\\{$studly}";
        $this->newLine();
        $this->components->info("Module '{$studly}' scaffolded. Finish wiring it:");
        $this->line('');
        $this->line('  <fg=yellow>1.</> app/Providers/AppServiceProvider.php → Relation::morphMap([...]):');
        $this->line("       '{$key}' => \\{$ns}\\Models\\{$studly}::class,");
        $this->line('');
        $this->line("  <fg=yellow>2.</> config/files.php → allowed_owner_types: add \\{$ns}\\Models\\{$studly}::class");
        $this->line('');
        $this->line('  <fg=yellow>3.</> database/seeders/ModuleSeeder.php → add a row:');
        $this->line("       ['key' => '{$key}', 'name' => '{$label}', 'morph_alias' => '{$key}', 'enabled' => config('{$key}.enabled', true)],");
        $this->line('');
        $this->line("  <fg=yellow>4.</> app/Domains/Modules/Services/ModuleRegistry.php → configDefaults(): '{$key}' => (bool) config('{$key}.enabled', true),");
        $this->line('');
        $this->line("  <fg=yellow>5.</> config/dashboard.php → widgets: add \\{$ns}\\Dashboard\\{$studly}DashboardWidget::class");
        $this->line('');
        $this->line("  <fg=yellow>6.</> routes/workspace.php → copy the equipment block, s/equipment/{$route}/ (and the controller import), gated by ->middleware('module:{$key}')");
        $this->line('');
        $this->line("  <fg=yellow>7.</> resources/js/composables/useSidebarItems.ts → add an entry gated on page.props.modules?.{$key}");
        $this->line('');
        $this->line("  <fg=yellow>8.</> resources/js/Pages/Dashboard.vue → import {$studly}Widget + add to widgetComponents map");
        $this->line('');
        $this->line("  <fg=yellow>9.</> resources/js/i18n/{en,no}.ts → add a `{$key}:` block + `rail.{$key}` (see lang/en/{$key}.php for the keys the Vue pages use)");
        $this->line('');
        $this->line('  Then: <fg=green>php artisan migrate</> and add the module to your test suite.');
        $this->newLine();
    }
}
