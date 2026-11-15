<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * Profiles stay readable for every role; managing accounts is an
 * administrative action.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::UsersView->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(PermissionName::UsersView->value);
    }

    public function create(User $user, mixed $draft = null): bool
    {
        return $user->can(PermissionName::UsersManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(PermissionName::UsersManage->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(PermissionName::UsersManage->value);
    }
}
