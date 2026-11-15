<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Group;
use App\Models\User;

/**
 * Group management is administrative: only the role that holds
 * `groups.manage` sees and changes groups, their composition and hierarchy.
 */
class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::GroupsManage->value);
    }

    public function view(User $user, Group $group): bool
    {
        return $user->can(PermissionName::GroupsManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::GroupsManage->value);
    }

    public function update(User $user, Group $group): bool
    {
        return $user->can(PermissionName::GroupsManage->value);
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->can(PermissionName::GroupsManage->value);
    }
}
