<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Board;
use App\Models\User;

/**
 * Matches the permissive item and label policies: any signed-in user may
 * manage boards. Tightening this later is a policy change only, not a schema
 * or UI change.
 */
class BoardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Board $board): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Board $board): bool
    {
        return true;
    }

    public function delete(User $user, Board $board): bool
    {
        return true;
    }

    public function restore(User $user, Board $board): bool
    {
        return true;
    }

    public function forceDelete(User $user, Board $board): bool
    {
        return true;
    }
}
