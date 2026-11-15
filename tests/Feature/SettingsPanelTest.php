<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
});

function actingAsAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    test()->actingAs($user);

    return $user;
}

function actingAsNonAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('manager');

    test()->actingAs($user);

    return $user;
}

test('admin can access settings panel', function (string $path) {
    actingAsAdmin();

    $this->get($path)->assertOk();
})->with([
    '/settings',
    '/settings/users',
    '/settings/users/create',
]);

test('non-admin cannot access settings panel', function (string $path) {
    actingAsNonAdmin();

    $this->get($path)->assertForbidden();
})->with([
    '/settings',
    '/settings/users',
    '/settings/users/create',
]);

test('guest is redirected to the settings panel login', function (string $path) {
    $this->get($path)->assertRedirect('/settings/login');
})->with([
    '/settings',
    '/settings/users',
]);

test('general settings live at the panel root without a duplicated segment', function () {
    actingAsAdmin();

    $this->get('/settings')
        ->assertOk()
        ->assertSee('Site name');

    $this->get('/settings/settings')->assertNotFound();
});

test('settings panel offers a way back to the main panel', function () {
    actingAsAdmin();

    $this->get('/settings')
        ->assertOk()
        ->assertSee('Back to app')
        ->assertSee('href="'.url('/').'"', escape: false);
});

test('users are no longer served by the main panel', function () {
    actingAsAdmin();

    $this->get('/users')->assertNotFound();
});

test('main panel offers settings in the user menu to admins', function () {
    actingAsAdmin();

    $this->get('/')
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee('/settings', escape: false);
});

test('main panel hides settings from non-admins', function () {
    actingAsNonAdmin();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('/settings', escape: false);
});

test('github link lives in the settings panel', function () {
    actingAsAdmin();

    $this->get('/settings')
        ->assertOk()
        ->assertSee('https://github.com/anatolii-iumashev/agiletracker', escape: false);
});

test('main panel carries no github link', function () {
    actingAsAdmin();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('https://github.com/anatolii-iumashev/agiletracker', escape: false);
});

test('non-admin keeps access to the working pages of the main panel', function (string $path) {
    actingAsNonAdmin();

    $this->get($path)->assertOk();
})->with([
    '/',
    '/i',
    '/labels',
    '/inbox',
    '/boards',
    '/favorite',
    '/sent',
]);
