<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The only place that changes a user's base role. It keeps the installation
 * from losing its last administrator and records the change as
 * `roles_updated`, so the audit log shows who promoted whom.
 */
final class AssignUserRole
{
    /**
     * @return bool whether the role actually changed
     *
     * @throws ValidationException when the change would remove the last admin
     */
    public function __invoke(User $subject, RoleName $role, ?User $actor = null, string $field = 'data.role'): bool
    {
        return DB::transaction(function () use ($subject, $role, $actor, $field): bool {
            $current = $this->baseRoleOf($subject);

            if ($current === $role) {
                return false;
            }

            if ($current === RoleName::Admin && ! $this->hasAnotherAdmin($subject)) {
                throw ValidationException::withMessages([
                    $field => __('The last administrator cannot lose the admin role.'),
                ]);
            }

            $subject->syncRoles([$role->value]);

            activity()
                ->performedOn($subject)
                ->causedBy($actor)
                ->event('roles_updated')
                ->withChanges([
                    'attributes' => ['role' => $role->value],
                    'old' => ['role' => $current?->value],
                ])
                ->log('roles_updated');

            return true;
        });
    }

    private function baseRoleOf(User $user): ?RoleName
    {
        return RoleName::mostPrivileged($user->getRoleNames()->all());
    }

    /**
     * Locks the admin rows for the duration of the transaction, so two
     * concurrent demotions cannot both see a second administrator.
     */
    private function hasAnotherAdmin(User $subject): bool
    {
        $adminIds = User::query()
            ->role(RoleName::Admin->value)
            ->lockForUpdate()
            ->pluck('id');

        return $adminIds->reject(fn (int|string $id): bool => (int) $id === (int) $subject->getKey())->isNotEmpty();
    }

    /**
     * Assigns the default role to a freshly created user that has none.
     */
    public function assignDefault(User $subject): void
    {
        if ($subject->roles()->exists()) {
            return;
        }

        $subject->assignRole(Role::findOrCreate(RoleName::User->value, 'web'));
    }
}
