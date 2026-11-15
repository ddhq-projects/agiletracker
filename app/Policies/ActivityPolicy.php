<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\User;

/**
 * The audit journal is read-only and administrative: only a role that holds
 * `activity.view` may read it, and no one may write a row through the UI.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ActivityView->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can(PermissionName::ActivityView->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
