<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->user = User::factory()->create();
});

it('renders the tokens page', function () {
    $this->actingAs($this->user)
        ->get('/settings/tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/ApiTokens')->has('tokens'));
});

it('creates a token and flashes the plain-text value exactly once', function () {
    $response = $this->actingAs($this->user)
        ->post('/settings/tokens', ['name' => 'Deploy bot']);

    $response->assertRedirect();
    $response->assertSessionHas('new_token');

    expect($this->user->tokens()->count())->toBe(1);
    expect($this->user->tokens()->first()->name)->toBe('Deploy bot');
});

it('validates a missing token name', function () {
    $this->actingAs($this->user)
        ->post('/settings/tokens', [])
        ->assertSessionHasErrors('name');
});

it('revokes a token the user owns', function () {
    $token = $this->user->createToken('To go');
    $id = $token->accessToken->id;

    $this->actingAs($this->user)->delete("/settings/tokens/{$id}")->assertRedirect();

    expect($this->user->tokens()->count())->toBe(0);
});

it('refuses to revoke a token belonging to another user', function () {
    $other = User::factory()->create();
    $token = $other->createToken('not mine');

    $this->actingAs($this->user)
        ->delete('/settings/tokens/'.$token->accessToken->id)
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->get('/settings/tokens')->assertRedirect('/login');
    $this->post('/settings/tokens', ['name' => 'x'])->assertRedirect('/login');
});
