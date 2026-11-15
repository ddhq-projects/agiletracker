<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the role and permission catalogue and normalises existing users.
 * Idempotent on purpose: it is the mandatory upgrade step of an existing
 * installation, so it can run on every deploy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // The registrar caches the permission list; without this reset the
        // sync below cannot resolve the names it was just given.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([RoleName::Manager, RoleName::User] as $roleName) {
            Role::findByName($roleName->value, 'web')
                ->syncPermissions(PermissionName::forRole($roleName));
        }

        $this->normaliseUsers();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Every user ends up with exactly one base role: the most privileged one
     * already held, or `user` when the record has none.
     */
    private function normaliseUsers(): void
    {
        User::query()
            ->with('roles')
            ->chunkById(100, function (Collection $users): void {
                /** @var User $user */
                foreach ($users as $user) {
                    $baseRoles = $user->roles
                        ->pluck('name')
                        ->filter(fn (string $name): bool => RoleName::tryFrom($name) !== null)
                        ->values();

                    $role = RoleName::mostPrivileged($baseRoles->all()) ?? RoleName::User;

                    if ($baseRoles->count() === 1 && $baseRoles->first() === $role->value) {
                        continue;
                    }

                    $user->syncRoles([$role->value]);
                }
            });
    }
}
