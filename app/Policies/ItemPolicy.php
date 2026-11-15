<?php

declare(strict_types=1);

namespace App\Policies;

use App\Access\ItemAccess;
use App\Enums\PermissionName;
use App\Models\Item;
use App\Models\User;

/**
 * The intersection of the two barriers: the global role permission and the
 * row-level access of the item. Neither one alone allows an operation.
 */
class ItemPolicy
{
    public function __construct(private readonly ItemAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ItemsView->value);
    }

    public function view(User $user, Item $item): bool
    {
        return $this->access->view($user, $item);
    }

    public function create(User $user, mixed $draft = null): bool
    {
        return $user->can(PermissionName::ItemsCreate->value);
    }

    public function update(User $user, Item $item): bool
    {
        return $this->access->update($user, $item);
    }

    public function delete(User $user, Item $item): bool
    {
        return $this->access->delete($user, $item);
    }

    /**
     * Who may change the project, the visibility and the groups of the item.
     */
    public function manageAccess(User $user, Item $item): bool
    {
        return $this->access->manageAccess($user, $item);
    }
}
