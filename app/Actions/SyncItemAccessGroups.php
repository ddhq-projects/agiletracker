<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes the groups attached to an item as access participants. Membership is
 * dynamic on purpose: the group is stored, not its members, so a new member
 * gains access without touching the item.
 */
final class SyncItemAccessGroups
{
    /**
     * @param  array<int, int|string>  $groupIds
     * @return bool whether the composition changed
     */
    public function __invoke(Item $item, array $groupIds, ?User $actor = null): bool
    {
        $desired = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $groupIds,
        )));

        return DB::transaction(function () use ($item, $desired, $actor): bool {
            $current = $item->accessGroups()->pluck('groups.id')->all();

            $added = array_values(array_diff($desired, $current));
            $removed = array_values(array_diff($current, $desired));

            if ($added === [] && $removed === []) {
                return false;
            }

            $item->accessGroups()->sync($desired);

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event('access_updated')
                ->withChanges([
                    'attributes' => ['groups_added' => $added, 'groups_removed' => $removed],
                    'old' => ['groups_added' => [], 'groups_removed' => $removed],
                ])
                ->log('access_updated');

            return true;
        });
    }
}
