<?php

declare(strict_types=1);

use App\Actions\AssignUserRole;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Filament\Settings\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ─── Catalogue ───────────────────────────────────────────────────────────

test('the seeder creates the three base roles and one permission catalogue', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['admin', 'manager', 'user'])
        ->and(Permission::query()->count())->toBe(count(PermissionName::cases()))
        ->and(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionName::values())->sort()->values()->all());
});

test('a second seeder run changes nothing and keeps the catalogue unique', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->count())->toBe(3)
        ->and(Permission::query()->count())->toBe(count(PermissionName::cases()))
        ->and(Role::findByName('manager')->permissions()->count())
        ->toBe(count(PermissionName::forRole(RoleName::Manager)));
});

test('a user without a role falls back to the user role', function () {
    $user = User::query()->create([
        'name' => 'Late joiner',
        'email' => 'late@example.com',
        'password' => 'secret-password',
    ]);

    expect($user->getRoleNames()->all())->toBe([RoleName::User->value]);
});

test('the seeder collapses several base roles into the most privileged one', function () {
    $user = User::factory()->create();
    $user->syncRoles([RoleName::User->value, RoleName::Manager->value, RoleName::Admin->value]);

    $this->seed(RolesAndPermissionsSeeder::class);

    expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Admin->value]);
});

// ─── The matrix ──────────────────────────────────────────────────────────

dataset('role matrix', function () {
    foreach (RoleName::cases() as $role) {
        foreach (PermissionName::cases() as $permission) {
            $expected = $role === RoleName::Admin
                || in_array($permission->value, PermissionName::forRole($role), true);

            yield $role->value.' '.$permission->value => [$role, $permission, $expected];
        }
    }
});

test('a role grants exactly the permissions of the matrix', function (RoleName $role, PermissionName $permission, bool $expected) {
    $user = User::factory()->create();
    $user->syncRoles([$role->value]);

    expect($user->can($permission->value))->toBe($expected);
})->with('role matrix');

test('no user receives a direct permission', function (RoleName $role) {
    $user = User::factory()->create();
    $user->syncRoles([$role->value]);

    expect($user->getDirectPermissions())->toBeEmpty();
})->with(RoleName::cases());

// ─── Assignment ──────────────────────────────────────────────────────────

function administrator(): User
{
    $admin = User::factory()->create();
    $admin->syncRoles([RoleName::Admin->value]);

    return $admin;
}

test('the last administrator cannot lose the admin role', function () {
    $admin = administrator();
    $this->actingAs($admin);

    expect(fn () => app(AssignUserRole::class)($admin, RoleName::User, $admin))
        ->toThrow(ValidationException::class);

    expect($admin->fresh()->hasRole(RoleName::Admin->value))->toBeTrue();
});

test('an administrator can be demoted while another one remains', function () {
    $first = administrator();
    $second = administrator();
    $this->actingAs($second);

    app(AssignUserRole::class)($first, RoleName::Manager, $second);

    expect($first->fresh()->getRoleNames()->all())->toBe([RoleName::Manager->value]);
});

test('a role change is recorded once as roles_updated', function () {
    $admin = administrator();
    $target = User::factory()->create();
    $this->actingAs($admin);

    app(AssignUserRole::class)($target, RoleName::Manager, $admin);

    $activity = $target->activitiesAsSubject()->where('event', 'roles_updated')->get();

    expect($activity)->toHaveCount(1)
        ->and($activity->first()->causer_id)->toBe($admin->getKey())
        ->and($activity->first()->attribute_changes->get('attributes'))->toBe(['role' => 'manager'])
        ->and($activity->first()->attribute_changes->get('old'))->toBe(['role' => 'user']);
});

// ─── Settings UI ─────────────────────────────────────────────────────────

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('settings'));
});

test('the settings user form offers one role and saves it', function () {
    $admin = administrator();
    $this->actingAs($admin);
    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->assertFormSet(['role' => RoleName::User->value])
        ->fillForm(['role' => RoleName::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->getRoleNames()->all())->toBe([RoleName::Manager->value]);
});

test('the settings user form refuses the self demotion of the last administrator', function () {
    $admin = administrator();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['role' => RoleName::Manager->value])
        ->call('save')
        ->assertHasFormErrors(['role']);
});

test('settings access follows the settings.manage permission', function (RoleName $role) {
    $user = User::factory()->create();
    $user->syncRoles([$role->value]);
    $this->actingAs($user);

    $this->get('/settings')->assertStatus(
        $role === RoleName::Admin ? 200 : 403,
    );
})->with(RoleName::cases());
