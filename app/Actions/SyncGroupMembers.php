<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes the composition of a group: who belongs to it and who leads it. The
 * pivot rows are replaced in one go, and the difference is recorded as
 * `group_members_updated`, so the audit log shows who entered and left.
 */
final class SyncGroupMembers
{
    /**
     * @param  array<int, int|string>  $memberIds
     * @param  array<int, int|string>  $managerIds
     * @return bool whether the composition changed
     */
    public function __invoke(Group $group, array $memberIds, array $managerIds, ?User $actor = null): bool
    {
        $desired = $this->desiredRoles($memberIds, $managerIds);

        return DB::transaction(function () use ($group, $desired, $actor): bool {
            $current = $group->users()
                ->get()
                ->mapWithKeys(fn (User $user): array => [(int) $user->getKey() => (string) $user->pivot->role])
                ->all();

            $added = array_keys(array_diff_key($desired, $current));
            $removed = array_keys(array_diff_key($current, $desired));
            $roleChanges = [];

            foreach ($desired as $userId => $role) {
                $isNew = ! isset($current[$userId]);

                if ($isNew || $current[$userId] !== $role) {
                    $roleChanges[$userId] = $role;
                }
            }

            if ($added === [] && $removed === [] && $roleChanges === []) {
                return false;
            }

            $sync = [];

            foreach ($desired as $userId => $role) {
                $sync[$userId] = ['role' => $role];
            }

            $group->users()->sync($sync);

            $oldRoles = [];

            foreach (array_keys($roleChanges) as $userId) {
                $oldRoles[$userId] = $current[$userId] ?? null;
            }

            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('group_members_updated')
                ->withChanges([
                    'attributes' => [
                        'members_added' => $added,
                        'members_removed' => $removed,
                        'member_roles' => $roleChanges,
                    ],
                    'old' => [
                        'members_added' => [],
                        'members_removed' => $removed,
                        'member_roles' => $oldRoles,
                    ],
                ])
                ->log('group_members_updated');

            return true;
        });
    }

    /**
     * A user listed as a manager is a manager, whatever the member list says.
     *
     * @param  array<int, int|string>  $memberIds
     * @param  array<int, int|string>  $managerIds
     * @return array<int, string>
     */
    private function desiredRoles(array $memberIds, array $managerIds): array
    {
        $desired = [];

        foreach ($memberIds as $id) {
            $desired[(int) $id] = GroupMemberRole::Member->value;
        }

        foreach ($managerIds as $id) {
            $desired[(int) $id] = GroupMemberRole::Manager->value;
        }

        ksort($desired);

        return $desired;
    }
}
