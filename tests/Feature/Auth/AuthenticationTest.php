<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('inactive users cannot log in', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => __('auth.inactive')]);

    $this->assertGuest();
});

test('inactive users see the generic error when the password is wrong', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);
});

test('a user deactivated mid-session is signed out on their next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['is_active' => false]);

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('successful login records the last login time', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('public registration is disabled', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::where('email', 'someone@example.com')->exists())->toBeFalse();
});

test('the home page sends guests to the login screen', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
});
