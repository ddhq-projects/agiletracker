<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Board;
use App\Models\User;

/**
 * A board's own structure — name, columns and their order — is a manager
 * action. Putting a card on it is a different question, answered per item by
 * ItemPolicy, so board access never grants access to its cards.
 */
class BoardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BoardsView->value);
    }

    public function view(User $user, Board $board): bool
    {
        return $user->can(PermissionName::BoardsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BoardsManage->value);
    }

    public function update(User $user, Board $board): bool
    {
        return $user->can(PermissionName::BoardsManage->value);
    }

    public function delete(User $user, Board $board): bool
    {
        return $user->can(PermissionName::BoardsManage->value);
    }

    public function restore(User $user, Board $board): bool
    {
        return $user->can(PermissionName::BoardsManage->value);
    }

    public function forceDelete(User $user, Board $board): bool
    {
        return $user->can(PermissionName::BoardsManage->value);
    }
}
