<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three base roles of the application. A user holds exactly one of them:
 * every other decision about what the user may do is derived from the role
 * permissions and, for items, from the row-level access rules.
 */
enum RoleName: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case User = 'user';

    /**
     * Most privileged first. The seeder collapses a user that ended up with
     * several base roles into the first one of this order.
     *
     * @return list<self>
     */
    public static function byPrivilege(): array
    {
        return [self::Admin, self::Manager, self::User];
    }

    /**
     * The most privileged of the given role names, or null when none of them
     * is a base role.
     *
     * @param  iterable<int|string, string>  $names
     */
    public static function mostPrivileged(iterable $names): ?self
    {
        $names = is_array($names) ? $names : iterator_to_array($names, false);

        foreach (self::byPrivilege() as $candidate) {
            if (in_array($candidate->value, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::User => 'User',
        };
    }
}
