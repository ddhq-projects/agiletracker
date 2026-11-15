<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // The role and permission catalogue is part of the installed
        // application, so every feature test starts from it.
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" API (PHP functions).
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user and authenticate.
 */
function actingAsUser(): User
{
    $user = User::factory()->create();

    test()->actingAs($user);

    return $user;
}

/**
 * Create a user with the given base role and authenticate.
 */
function actingAsRole(RoleName $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role->value]);

    test()->actingAs($user);

    return $user;
}

/**
 * A manager holds the shared permissions plus the management ones.
 */
function actingAsManager(): User
{
    return actingAsRole(RoleName::Manager);
}

/**
 * A manager authenticated for the API v1 with the given token abilities.
 *
 * @param  list<string>  $abilities
 */
function actingAsManagerApi(array $abilities): User
{
    return actingAsApi($abilities, actingAsManagerSolo());
}

/**
 * Build a manager without touching the current authentication guard.
 */
function actingAsManagerSolo(): User
{
    $user = User::factory()->create();
    $user->syncRoles([RoleName::Manager->value]);

    return $user;
}

/**
 * Authenticate the user for the API v1 with the given token abilities.
 *
 * @param  list<string>  $abilities
 */
function actingAsApi(array $abilities, ?User $user = null): User
{
    $user ??= User::factory()->create();

    Sanctum::actingAs($user, $abilities);

    return $user;
}
