<?php

declare(strict_types=1);

namespace App\Access;

use App\Enums\ItemAccessBasis;
use App\Enums\ItemVisibility;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Group;
use App\Models\Item;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single row-level rule of the application. The SQL scope and the
 * per-record checks are two faces of the same logic, so a list, a detail
 * page, a board and the REST API never disagree about who sees what.
 *
 * The result is always intersected with the global role permissions: a grant
 * never lets a user do more than the role allows, and the role never widens
 * the perimeter of a restricted item.
 */
final class ItemAccess
{
    /**
     * @var array<int, list<int>>
     */
    private array $managedGroupIds = [];

    /**
     * @var array<int, list<int>>
     */
    private array $groupIdsOfUser = [];

    /**
     * The fields the assignee may still change on a restricted item.
     *
     * @var list<string>
     */
    public const array LIMITED_UPDATE_ATTRIBUTES = ['status', 'assignee_id'];

    public function isAdmin(User $user): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    /**
     * Limits a query to the items the user may see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($this->isAdmin($user)) {
            return $query;
        }

        $userId = (int) $user->getKey();
        $managedGroupIds = $this->managedGroupIds($user);

        return $query->where(function (Builder $query) use ($userId, $managedGroupIds): void {
            $query->where('visibility', ItemVisibility::Public->value)
                ->orWhere('reporter_id', $userId)
                ->orWhere('assignee_id', $userId)
                ->orWhereHas('to', fn (Builder $query): Builder => $query->whereKey($userId))
                ->orWhereHas('cc', fn (Builder $query): Builder => $query->whereKey($userId))
                ->orWhereHas('accessGroups.users', fn (Builder $query): Builder => $query->whereKey($userId));

            if ($managedGroupIds === []) {
                return;
            }

            // The item itself is shared with a group the user leads.
            $query->orWhereHas(
                'accessGroups',
                fn (Builder $query): Builder => $query->whereIn('groups.id', $managedGroupIds),
            );

            // The item belongs to a project the user leads.
            $query->orWhereIn('project_group_id', $managedGroupIds);

            // A participant belongs to a group the user leads.
            foreach (['reporter', 'assignee', 'to', 'cc'] as $relation) {
                $query->orWhereHas(
                    $relation,
                    fn (Builder $query): Builder => $query->whereHas(
                        'groups',
                        fn (Builder $query): Builder => $query->whereIn('groups.id', $managedGroupIds),
                    ),
                );
            }
        });
    }

    /**
     * Every basis that gives this user access to the item.
     *
     * @return list<ItemAccessBasis>
     */
    public function bases(User $user, Item $item): array
    {
        if ($this->isAdmin($user)) {
            return [ItemAccessBasis::Admin];
        }

        $userId = (int) $user->getKey();
        $bases = [];

        if ((int) $item->reporter_id === $userId) {
            $bases[] = ItemAccessBasis::Reporter;
        }

        if ((int) $item->assignee_id === $userId) {
            $bases[] = ItemAccessBasis::Assignee;
        }

        if (in_array($userId, $this->relatedUserIds($item, 'to'), true)) {
            $bases[] = ItemAccessBasis::Recipient;
        }

        if (in_array($userId, $this->relatedUserIds($item, 'cc'), true)) {
            $bases[] = ItemAccessBasis::Observer;
        }

        if (in_array($userId, $this->groupMemberIds($item), true)) {
            $bases[] = ItemAccessBasis::GroupMember;
        }

        $managedGroupIds = $this->managedGroupIds($user);

        if ($managedGroupIds === []) {
            return $bases;
        }

        if ($item->project_group_id !== null
            && in_array((int) $item->project_group_id, $managedGroupIds, true)) {
            $bases[] = ItemAccessBasis::ProjectManager;
        }

        if ($this->itemGroupIds($item) !== [] && array_intersect($this->itemGroupIds($item), $managedGroupIds) !== []) {
            $bases[] = ItemAccessBasis::ManagedGroupMember;
        }

        $participants = [
            ItemAccessBasis::ManagedReporter->value => [(int) $item->reporter_id],
            ItemAccessBasis::ManagedAssignee->value => [(int) $item->assignee_id],
            ItemAccessBasis::ManagedRecipient->value => $this->relatedUserIds($item, 'to'),
            ItemAccessBasis::ManagedObserver->value => $this->relatedUserIds($item, 'cc'),
        ];

        foreach ($participants as $basisValue => $userIds) {
            foreach ($userIds as $participantId) {
                if ($participantId === 0 || $participantId === $userId) {
                    continue;
                }

                if ($this->userInGroups($participantId, $managedGroupIds)) {
                    $bases[] = ItemAccessBasis::from($basisValue);

                    break;
                }
            }
        }

        return $bases;
    }

    public function view(User $user, Item $item): bool
    {
        if (! $user->can(PermissionName::ItemsView->value)) {
            return false;
        }

        if ($item->isPublic()) {
            return true;
        }

        return $this->bases($user, $item) !== [];
    }

    public function update(User $user, Item $item): bool
    {
        if (! $user->can(PermissionName::ItemsView->value)) {
            return false;
        }

        $bases = $this->bases($user, $item);

        if (! $item->isPublic() && $bases === []) {
            return false;
        }

        if (! $this->hasGlobalUpdate($user, $item, $bases)) {
            return false;
        }

        if ($item->isPublic()) {
            return true;
        }

        foreach ($bases as $basis) {
            if ($basis->allowsFullUpdate() || $basis->allowsLimitedUpdate()) {
                return true;
            }
        }

        return false;
    }

    public function delete(User $user, Item $item): bool
    {
        if (! $this->view($user, $item) || ! $user->can(PermissionName::ItemsDelete->value)) {
            return false;
        }

        if ($item->isPublic()) {
            return true;
        }

        foreach ($this->bases($user, $item) as $basis) {
            if ($basis->allowsDelete()) {
                return true;
            }
        }

        return false;
    }

    public function manageAccess(User $user, Item $item): bool
    {
        if (! $this->view($user, $item)) {
            return false;
        }

        foreach ($this->bases($user, $item) as $basis) {
            if ($basis->allowsAccessManagement()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The attributes this user may write, or `null` for every field. An empty
     * list means the item is not editable at all.
     *
     * @return list<string>|null
     */
    public function updatableAttributes(User $user, Item $item): ?array
    {
        if (! $this->update($user, $item)) {
            return [];
        }

        if ($item->isPublic()) {
            return null;
        }

        $bases = $this->updatableBases($user, $item);

        foreach ($bases as $basis) {
            if ($basis->allowsFullUpdate()) {
                return null;
            }
        }

        return $bases === [] ? [] : self::LIMITED_UPDATE_ATTRIBUTES;
    }

    /**
     * @return list<ItemAccessBasis>
     */
    public function updatableBases(User $user, Item $item): array
    {
        return array_values(array_filter(
            $this->bases($user, $item),
            fn (ItemAccessBasis $basis): bool => $basis->allowsFullUpdate() || $basis->allowsLimitedUpdate(),
        ));
    }

    /**
     * `items.update.all` covers every visible item, `items.update.related`
     * only the ones the user reported, was assigned or leads the reporter or
     * the assignee of.
     *
     * @param  list<ItemAccessBasis>  $bases
     */
    private function hasGlobalUpdate(User $user, Item $item, array $bases): bool
    {
        if ($user->can(PermissionName::ItemsUpdateAll->value)) {
            return true;
        }

        if (! $user->can(PermissionName::ItemsUpdateRelated->value)) {
            return false;
        }

        foreach ($bases as $basis) {
            if (in_array($basis, [
                ItemAccessBasis::Reporter,
                ItemAccessBasis::Assignee,
                ItemAccessBasis::ManagedReporter,
                ItemAccessBasis::ManagedAssignee,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The groups the user leads, including every group below them.
     *
     * @return list<int>
     */
    public function managedGroupIds(User $user): array
    {
        $userId = (int) $user->getKey();

        if (array_key_exists($userId, $this->managedGroupIds)) {
            return $this->managedGroupIds[$userId];
        }

        $ledGroupIds = DB::table('group_user')
            ->where('user_id', $userId)
            ->where('role', 'manager')
            ->pluck('group_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $collected = [];

        if ($ledGroupIds !== []) {
            $groups = Group::query()->whereIn('id', $ledGroupIds)->get();

            foreach ($groups as $group) {
                $collected = [...$collected, ...$group->selfAndDescendantIds()];
            }
        }

        return $this->managedGroupIds[$userId] = array_values(array_unique($collected));
    }

    /**
     * @return list<int>
     */
    private function relatedUserIds(Item $item, string $relation): array
    {
        /** @var Collection<int, User> $users */
        $users = $item->relationLoaded($relation)
            ? $item->getRelation($relation)
            : $item->{$relation}()->get();

        return $users->modelKeys();
    }

    /**
     * @return list<int>
     */
    private function itemGroupIds(Item $item): array
    {
        if ($item->relationLoaded('accessGroups')) {
            return $item->getRelation('accessGroups')->modelKeys();
        }

        return $item->accessGroups()->pluck('groups.id')->all();
    }

    /**
     * The users of every group attached to the item.
     *
     * @return list<int>
     */
    private function groupMemberIds(Item $item): array
    {
        $groupIds = $this->itemGroupIds($item);

        if ($groupIds === []) {
            return [];
        }

        return DB::table('group_user')
            ->whereIn('group_id', $groupIds)
            ->pluck('user_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $groupIds
     */
    private function userInGroups(int $userId, array $groupIds): bool
    {
        if (array_key_exists($userId, $this->groupIdsOfUser)) {
            return array_intersect($this->groupIdsOfUser[$userId], $groupIds) !== [];
        }

        $ids = DB::table('group_user')
            ->where('user_id', $userId)
            ->pluck('group_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->groupIdsOfUser[$userId] = $ids;

        return array_intersect($ids, $groupIds) !== [];
    }
}
