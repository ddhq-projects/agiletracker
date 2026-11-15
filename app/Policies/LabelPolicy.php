<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Label;
use App\Models\User;

class LabelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::LabelsView->value);
    }

    public function view(User $user, Label $label): bool
    {
        return $user->can(PermissionName::LabelsView->value);
    }

    public function create(User $user, mixed $draft = null): bool
    {
        return $user->can(PermissionName::LabelsManage->value);
    }

    public function update(User $user, Label $label): bool
    {
        return $user->can(PermissionName::LabelsManage->value);
    }

    public function delete(User $user, Label $label): bool
    {
        return $user->can(PermissionName::LabelsManage->value);
    }
}
