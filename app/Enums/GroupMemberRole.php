<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a user takes part in a group. A manager is also a member: the role adds
 * the rights of the group's members and of the groups below it.
 */
enum GroupMemberRole: string
{
    case Member = 'member';
    case Manager = 'manager';

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
        return [
            self::Member->value => 'Member',
            self::Manager->value => 'Manager',
        ];
    }
}
