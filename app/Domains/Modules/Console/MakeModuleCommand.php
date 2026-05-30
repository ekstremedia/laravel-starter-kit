<?php

declare(strict_types=1);

namespace App\Domains\Modules\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
    protected $signature = 'make:module {name : Studly singular name, e.g. Car}
        {--no-files : Scaffold a lean module with no file area (no uploads/cover/zip)}
        {--no-log : Scaffold without an activity log}
        {--base= : Internal — write the generated tree under this directory instead of the project root (used by tests to avoid polluting config/ + app/)}';

    protected $description = 'Scaffold a new workspace-scoped module (CRUD + datatable; optionally files + log)';

    public function handle(): int
    {
        $studly = Str::studly($this->argument('name'));        // Car
        $key = Str::snake($studly);                            // car
        $pluralStudly = Str::pluralStudly($studly);            // Cars
        $table = Str::snake($pluralStudly);                    // cars
        $route = Str::kebab($pluralStudly);                    // cars (url + route-name segment)
        $label = Str::headline($studly);                       // Car
        $pluralLabel = Str::headline($pluralStudly);           // Cars

        // Composable features: which of the optional surfaces this module ships.
        // EquipmentCategory is the hand-built equivalent of `--no-files`.
        $withFiles = ! $this->option('no-files');
        $withLog = ! $this->option('no-log');

        if (in_array($key, ['user', 'workspace', 'equipment', 'equipment_category', 'module'], true)) {
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

        // Where the generated tree lands. Defaults to the project root; tests pass
        // a throwaway dir so generation never writes into the live config/ + app/
        // dirs the framework auto-loads on every boot (which races parallel test
        // workers — a stray config/<key>.php breaks their LoadConfiguration).
        $root = $this->option('base') ? rtrim((string) $this->option('base'), '/') : base_path();

        /** @var array<string, string> $files stub => target path */
        $files = [
            'model.stub' => "{$root}/app/Domains/{$studly}/Models/{$studly}.php",
            'controller.stub' => "{$root}/app/Domains/{$studly}/Http/Controllers/{$studly}Controller.php",
            'widget.stub' => "{$root}/app/Domains/{$studly}/Dashboard/{$studly}DashboardWidget.php",
            'config.stub' => "{$root}/config/{$key}.php",
            'factory.stub' => "{$root}/database/factories/{$studly}Factory.php",
            'seeder.stub' => "{$root}/database/seeders/{$studly}Seeder.php",
            'migration.stub' => "{$root}/database/migrations/{$migrationTs}_create_{$table}_table.php",
            'Index.vue.stub' => "{$root}/resources/js/Pages/{$pluralStudly}/Index.vue",
            'Show.vue.stub' => "{$root}/resources/js/Pages/{$pluralStudly}/Show.vue",
            'Trash.vue.stub' => "{$root}/resources/js/Pages/{$pluralStudly}/Trash.vue",
            'lang.stub' => "{$root}/lang/en/{$key}.php",
        ];

        /** @var array<int, string> $generatedPhp paths to Pint after generation */
        $generatedPhp = [];

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

            if (! $this->writeFile($target, $this->render($stubPath, $replacements, $withFiles, $withLog))) {
                return self::FAILURE;
            }
            if (str_ends_with($target, '.php')) {
                $generatedPhp[] = $target;
            }
            $this->info('Created: '.str_replace(base_path().'/', '', $target));
        }

        // Norwegian lang file (same stub, dev translates).
        $noLang = "{$root}/lang/no/{$key}.php";
        if (! is_file($noLang)) {
            if (! $this->writeFile($noLang, $this->render("{$stubDir}/lang.stub", $replacements, $withFiles, $withLog))) {
                return self::FAILURE;
            }
            $generatedPhp[] = $noLang;
            $this->info('Created: '.str_replace(base_path().'/', '', $noLang));
        }

        // Tidy the generated PHP so the output is style-clean regardless of the
        // module name (e.g. import ordering, which depends on the class name).
        // Skipped when targeting a custom base (tests) — purely cosmetic there.
        if (! $this->option('base')) {
            $this->formatGenerated($generatedPhp);
        }

        $this->printChecklist($studly, $key, $route, $label, $withFiles, $withLog);

        return self::SUCCESS;
    }

    /**
     * Read a stub, replace its tokens, then keep/strip the optional `@files` and
     * `@log` regions so the output matches the requested capabilities.
     *
     * @param  array<string, string>  $replacements
     */
    private function render(string $stubPath, array $replacements, bool $withFiles, bool $withLog): string
    {
        $content = strtr((string) file_get_contents($stubPath), $replacements);
        $content = $this->stripRegions($content, 'files', $withFiles);
        $content = $this->stripRegions($content, 'log', $withLog);

        // Collapse any blank-line runs left behind by stripped regions.
        return (string) preg_replace("/\n{3,}/", "\n\n", $content);
    }

    /**
     * Resolve a conditional region tagged `@<tag>` … (`@no<tag>` …) `@end<tag>`,
     * in both `//` (PHP/TS) and `<!-- -->` (Vue/HTML) comment styles. Keeps the
     * primary branch when $keep is true, the `@no<tag>` else-branch otherwise;
     * the marker lines themselves are always dropped. Regions of the same tag do
     * not nest (files and log are resolved in separate passes, so they may).
     */
    private function stripRegions(string $content, string $tag, bool $keep): string
    {
        $markers = [
            'start' => ["// @{$tag}", "<!-- @{$tag} -->"],
            'else' => ["// @no{$tag}", "<!-- @no{$tag} -->"],
            'end' => ["// @end{$tag}", "<!-- @end{$tag} -->"],
        ];

        $out = [];
        $state = 'outside'; // outside | keep | drop
        foreach (preg_split('/\n/', $content) ?: [] as $line) {
            $trim = trim($line);
            if (in_array($trim, $markers['start'], true)) {
                $state = $keep ? 'keep' : 'drop';

                continue;
            }
            if (in_array($trim, $markers['else'], true)) {
                $state = $keep ? 'drop' : 'keep';

                continue;
            }
            if (in_array($trim, $markers['end'], true)) {
                $state = 'outside';

                continue;
            }
            if ($state !== 'drop') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Best-effort Pint pass over the freshly generated PHP so its style matches
     * the codebase (import order in particular is class-name dependent). Silent
     * no-op when Pint isn't installed; never fails the generation.
     *
     * @param  array<int, string>  $paths
     */
    private function formatGenerated(array $paths): void
    {
        $pint = base_path('vendor/bin/pint');
        if ($paths === [] || ! is_file($pint)) {
            return;
        }

        try {
            Process::path(base_path())->run(array_merge([$pint, '--quiet'], $paths));
        } catch (\Throwable) {
            // Formatting is a nicety — a missing/odd Pint must not break scaffolding.
        }
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

    private function printChecklist(string $studly, string $key, string $route, string $label, bool $withFiles, bool $withLog): void
    {
        $ns = "App\\Domains\\{$studly}";
        // Capabilities the generated code ships, for the ModuleSeeder row.
        $caps = "['files' => ".($withFiles ? 'true' : 'false').", 'log' => ".($withLog ? 'true' : 'false').']';
        $morphAlias = $withFiles ? "'{$key}'" : 'null';

        $this->newLine();
        $this->components->info("Module '{$studly}' scaffolded (files: ".($withFiles ? 'yes' : 'no').', log: '.($withLog ? 'yes' : 'no').'). Finish wiring it:');
        $this->line('');
        $n = 1;
        $this->line("  <fg=yellow>{$n}.</> app/Providers/AppServiceProvider.php → Relation::morphMap([...]):");
        $this->line("       '{$key}' => \\{$ns}\\Models\\{$studly}::class,");
        $this->line('');
        $n++;
        if ($withFiles) {
            $this->line("  <fg=yellow>{$n}.</> config/files.php → allowed_owner_types: add \\{$ns}\\Models\\{$studly}::class");
            $this->line('');
            $n++;
        }
        $this->line("  <fg=yellow>{$n}.</> database/seeders/ModuleSeeder.php → add a row:");
        $this->line("       ['key' => '{$key}', 'name' => '{$label}', 'morph_alias' => {$morphAlias}, 'enabled' => config('{$key}.enabled', true), 'capabilities' => {$caps}],");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> app/Domains/Modules/Services/ModuleRegistry.php → configDefaults(): '{$key}' => (bool) config('{$key}.enabled', true),");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> config/dashboard.php → widgets: add \\{$ns}\\Dashboard\\{$studly}DashboardWidget::class");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> routes/workspace.php → copy the equipment block, s/equipment/{$route}/ (and the controller import), gated by ->middleware('module:{$key}')");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> resources/js/composables/useSidebarItems.ts → add an entry gated on page.props.modules?.{$key}?.enabled");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> resources/js/Pages/Dashboard.vue → import {$studly}Widget + add to widgetComponents map");
        $this->line('');
        $n++;
        $this->line("  <fg=yellow>{$n}.</> resources/js/i18n/{en,no}.ts → add a `{$key}:` block + `rail.{$key}` (see lang/en/{$key}.php for the keys the Vue pages use)");
        $this->line('');
        $this->line('  Then: <fg=green>php artisan migrate</> and add the module to your test suite.');
        $this->newLine();
    }
}
