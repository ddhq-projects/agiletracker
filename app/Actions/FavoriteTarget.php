<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * The single description of where a favorite points, shared by the full
 * Favorites page, the dashboard cards and the sidebar navigation.
 */
final class FavoriteTarget
{
    private function __construct(
        public readonly ?string $url,
        public readonly bool $isExternal,
    ) {}

    public static function link(string $url, bool $isExternal): self
    {
        return new self($url, $isExternal);
    }

    public static function unavailable(): self
    {
        return new self(null, false);
    }

    public function isAvailable(): bool
    {
        return $this->url !== null;
    }
}
