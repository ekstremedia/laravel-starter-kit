<?php

use App\Domains\Operations\Events\PingEvent;
use App\Domains\Operations\Jobs\PingJob;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('dispatches a PingJob on queue ping', function () {
    Bus::fake();

    $this->actingAs($this->admin)
        ->post('/admin/health/queue')
        ->assertRedirect();

    Bus::assertDispatched(PingJob::class);
});

it('broadcasts PingEvent on broadcast ping', function () {
    Event::fake();

    $this->actingAs($this->admin)
        ->post('/admin/health/broadcast')
        ->assertRedirect();

    Event::assertDispatched(PingEvent::class);
});

it('returns queue last as JSON', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/health/queue-last')
        ->assertOk()
        ->assertJsonStructure(['last']);
});
