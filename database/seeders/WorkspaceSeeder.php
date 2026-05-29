<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Seeds the default customer so the starter kit runs as a "single workspace"
 * app out of the box. The app always runs multi-tenant and needs at least one
 * customer to be reachable — this seeder guarantees that. The slug comes from
 * config so deployments can override it with the WORKSPACES_DEFAULT_WORKSPACE env
 * var.
 *
 * Creating the row fires stancl's TenantCreated pipeline, which in turn
 * provisions the `tenant<id>` Postgres schema and runs migrations in
 * `database/migrations/tenant/`.
 */
class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $slug = (string) config('workspaces.default_workspace_slug', 'default');

        Workspace::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => config('app.name', 'Default customer'),
                'status' => 'active',
                // Files (personal + company-shared) are on out of the box
                // with a 5 GB per-customer cap. Admins can flip the flags
                // or adjust the cap from /admin/customers/{id}/edit.
                'files_feature_enabled' => true,
                'company_files_enabled' => true,
                'storage_quota_bytes' => 5 * 1024 * 1024 * 1024,
            ],
        );
    }
}
