<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The catalogue of global permissions. A role is a named bundle of these, and
 * a policy always asks for a permission instead of a role name: the extra
 * row-level rules of restricted items are layered on top, never in place of
 * this matrix.
 *
 * `admin` is intentionally absent from the role matrix: it passes every gate
 * through `Gate::before`, and syncing permissions to it would only create a
 * second source of truth.
 */
enum PermissionName: string
{
    case ItemsView = 'items.view';
    case ItemsCreate = 'items.create';
    case ItemsUpdateRelated = 'items.update.related';
    case ItemsUpdateAll = 'items.update.all';
    case ItemsDelete = 'items.delete';
    case LabelsView = 'labels.view';
    case LabelsManage = 'labels.manage';
    case BoardsView = 'boards.view';
    case BoardsManage = 'boards.manage';
    case CommentsView = 'comments.view';
    case CommentsCreate = 'comments.create';
    case CommentsModerate = 'comments.moderate';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case GroupsManage = 'groups.manage';
    case SettingsManage = 'settings.manage';
    case ActivityView = 'activity.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $permission) {
            $options[$permission->value] = $permission->value;
        }

        return $options;
    }

    /**
     * The permissions a base role holds. Anything not listed here is denied
     * to that role, and no permission ever reaches beyond a row-level grant.
     *
     * @return list<string>
     */
    public static function forRole(RoleName $role): array
    {
        $shared = [
            self::ItemsView,
            self::ItemsCreate,
            self::ItemsUpdateRelated,
            self::LabelsView,
            self::BoardsView,
            self::CommentsView,
            self::CommentsCreate,
            self::UsersView,
        ];

        $permissions = match ($role) {
            RoleName::Admin => [],
            RoleName::Manager => [
                ...$shared,
                self::ItemsUpdateAll,
                self::ItemsDelete,
                self::LabelsManage,
                self::BoardsManage,
                self::CommentsModerate,
            ],
            RoleName::User => $shared,
        };

        return array_map(
            static fn (self $permission): string => $permission->value,
            $permissions,
        );
    }
}
