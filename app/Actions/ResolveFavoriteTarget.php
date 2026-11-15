<?php

declare(strict_types=1);

namespace App\Actions;

use App\Filament\Pages\UserProfile;
use App\Filament\Resources\ItemResource;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Resolves the destination of a favorite once, so the Favorites table, the
 * dashboard cards and the sidebar never disagree about a link.
 */
final class ResolveFavoriteTarget
{
    public function __invoke(Favorite $favorite, ?User $user = null): FavoriteTarget
    {
        $user ??= auth()->user();

        // A favorite of the "internal object" kind may still carry a legacy
        // `url` pointing at the same record, so the object wins when it is set.
        if ($favorite->favoritable_type !== null && $user instanceof User) {
            $target = $this->resolveFavoritable($favorite, $user);

            if ($target->isAvailable()) {
                return $target;
            }
        }

        if ($favorite->url !== null) {
            return $this->resolveExternalUrl($favorite->url);
        }

        return FavoriteTarget::unavailable();
    }

    private function resolveFavoritable(Favorite $favorite, User $user): FavoriteTarget
    {
        $favoritable = $favorite->favoritable;

        if ($favoritable === null) {
            return FavoriteTarget::unavailable();
        }

        if (! Gate::forUser($user)->allows('view', $favoritable)) {
            return FavoriteTarget::unavailable();
        }

        return match (true) {
            $favoritable instanceof Item => FavoriteTarget::link(
                ItemResource::getUrl('view', ['record' => $favoritable]),
                false,
            ),
            $favoritable instanceof User => FavoriteTarget::link(
                UserProfile::getUrl(['record' => $favoritable]),
                false,
            ),
            default => FavoriteTarget::unavailable(),
        };
    }

    private function resolveExternalUrl(string $url): FavoriteTarget
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return FavoriteTarget::unavailable();
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return FavoriteTarget::unavailable();
        }

        return FavoriteTarget::link($url, true);
    }
}
