<?php

namespace App\Domains\Auth\Actions;

use App\Domains\Notifications\Notifications\WelcomeNotification;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Actions\CreateWorkspace;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $settings = AppSetting::current();

        if ($settings->send_welcome_notification) {
            $user->notify(new WelcomeNotification);
        }

        // How the new user gets a workspace. In single-tenant mode (tenancy
        // off) everyone shares the one default workspace, so create_own is
        // ignored. In multi-tenant mode the configured registration_mode
        // decides between self-serve space creation and auto-join.
        if (config('workspaces.enabled') && config('workspaces.registration_mode') === 'create_own') {
            app(CreateWorkspace::class)->forOwner($user, $this->defaultWorkspaceName($user));
        } else {
            $this->attachToDefaultWorkspace($user, $settings->default_role ?? 'User');
        }

        return $user;
    }

    /**
     * Name for the workspace a self-serve sign-up creates, e.g. "Ada's space".
     */
    private function defaultWorkspaceName(User $user): string
    {
        $first = trim((string) $user->first_name);

        return $first !== '' ? "{$first}'s space" : 'My space';
    }

    /**
     * New sign-ups auto-join the default workspace configured in
     * `tenancy.default_workspace_slug` (env: `WORKSPACES_DEFAULT_WORKSPACE`) with the
     * platform-configured default role. Roles are always workspace-scoped, so we
     * go through `WorkspaceMembership` to keep the pivot + role assignment in
     * sync. When the configured slug doesn't resolve we log a warning — the
     * user would land on the picker with nowhere to go until an admin attaches
     * them, which is worth surfacing.
     */
    private function attachToDefaultWorkspace(User $user, string $defaultRole): void
    {
        $slug = config('workspaces.default_workspace_slug', 'default');

        $workspace = Workspace::query()->where('slug', $slug)->first();

        if ($workspace === null) {
            Log::warning('Default workspace not found for new user; skipping auto-join.', [
                'slug' => $slug,
                'user_id' => $user->id,
            ]);

            return;
        }

        // Fall back to 'User' if the configured default role isn't one we
        // recognise as assignable — mirroring the previous swallowed
        // RoleDoesNotExist behaviour so a bad app-setting value can't block
        // registration.
        $role = in_array($defaultRole, WorkspaceMembership::assignableRoles(), true)
            ? $defaultRole
            : 'User';

        try {
            WorkspaceMembership::attach($user, $workspace, [$role]);
        } catch (RoleDoesNotExist) {
            $user->workspaces()->syncWithoutDetaching([$workspace->id]);
        }
    }
}
