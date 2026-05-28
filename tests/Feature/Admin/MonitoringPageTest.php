<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('renders the monitoring page with the activity tab by default', function () {
    $this->actingAs($this->admin)
        ->get('/admin/monitoring')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Monitoring')
            ->where('tab', 'activity')
            ->has('activities.data')
            ->has('users')
            ->has('logNames')
            ->has('events')
            ->has('endpoints')
        );
});

it('accepts an explicit tab query param', function () {
    foreach (['logs', 'pulse', 'horizon'] as $tab) {
        $this->actingAs($this->admin)
            ->get('/admin/monitoring?tab='.$tab)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Monitoring')
                ->where('tab', $tab)
            );
    }
});

it('coerces unknown tab values back to activity', function () {
    $this->actingAs($this->admin)
        ->get('/admin/monitoring?tab=nonsense')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'activity'));
});

it('keeps the legacy /admin/activity URL working via redirect', function () {
    $this->actingAs($this->admin)
        ->get('/admin/activity')
        ->assertRedirect('/admin/monitoring?tab=activity');
});

it('filters activity by user', function () {
    $other = User::factory()->create();

    Activity::create(['log_name' => 'user', 'description' => 'a', 'causer_id' => $this->admin->id, 'causer_type' => (new User)->getMorphClass()]);
    Activity::create(['log_name' => 'user', 'description' => 'b', 'causer_id' => $other->id, 'causer_type' => (new User)->getMorphClass()]);

    $this->actingAs($this->admin)
        ->get('/admin/monitoring?user_id='.$this->admin->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data', fn ($rows) => collect($rows)->count() === 1
                && collect($rows)->first()['description'] === 'a')
        );
});

it('filters activity by log name', function () {
    Activity::create(['log_name' => 'auth', 'description' => 'login']);
    Activity::create(['log_name' => 'user', 'description' => 'updated']);

    $this->actingAs($this->admin)
        ->get('/admin/monitoring?log_name=auth')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data', fn ($rows) => collect($rows)->count() === 1)
        );
});

it('filters activity by date range', function () {
    $old = Activity::create(['log_name' => 'datefilter', 'description' => 'old']);
    DB::table('activity_log')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);
    Activity::create(['log_name' => 'datefilter', 'description' => 'recent']);

    $this->actingAs($this->admin)
        ->get('/admin/monitoring?date_from='.now()->subDays(2)->toDateString().'&log_name=datefilter')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data', fn ($rows) => collect($rows)->count() === 1
                && collect($rows)->first()['description'] === 'recent')
        );
});

it('rejects invalid filter payloads', function () {
    $this->actingAs($this->admin)
        ->get('/admin/monitoring?user_id=not-a-number')
        ->assertSessionHasErrors('user_id');
});

it('forbids non-admins from the monitoring page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/monitoring')->assertForbidden();
});
