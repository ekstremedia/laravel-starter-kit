<?php

use App\Domains\Users\Models\User;

it('shows the password confirmation page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/user/confirm-password')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Auth/ConfirmPassword'));
});

it('confirms password with correct password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password'])
        ->assertRedirect();
});

it('rejects incorrect password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'wrong-password'])
        ->assertSessionHasErrors();
});

it('requires authentication for password confirmation', function () {
    $this->get('/user/confirm-password')
        ->assertRedirect('/login');
});
