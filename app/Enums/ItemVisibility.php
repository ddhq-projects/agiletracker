<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can reach an item. `public` means every authenticated user with
 * `items.view`; `restricted` means only the participants listed on the item,
 * the members of its groups and their managers.
 */
enum ItemVisibility: string
{
    case Public = 'public';
    case Restricted = 'restricted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $visibility): string => $visibility->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Public->value => 'Public',
            self::Restricted->value => 'Restricted',
        ];
    }
}
