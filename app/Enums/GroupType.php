<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of a group. It is a classification that gives the group its
 * meaning — a project owns items, a team or department is an organisational
 * unit — not a separate table per kind.
 */
enum GroupType: string
{
    case Project = 'project';
    case Team = 'team';
    case Department = 'department';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Team => 'Team',
            self::Department => 'Department',
            self::Other => 'Other',
        };
    }
}
