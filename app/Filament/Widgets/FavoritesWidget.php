<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\FavoriteOrderService;
use App\Actions\FavoriteTarget;
use App\Actions\ResolveFavoriteTarget;
use App\Filament\Pages\Favorites;
use App\Models\Favorite;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class FavoritesWidget extends Widget
{
    /**
     * How many favorites the dashboard grid shows. The rest lives on the page.
     */
    public const CARD_LIMIT = 12;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.favorites-widget';

    /**
     * Drag-and-drop from the cards. The dashboard only knows about the
     * favorites it can currently link to, so the untouched ones keep their
     * stored positions.
     *
     * @param  array<int, int|string>  $order
     */
    public function reorderFavorites(array $order): void
    {
        app(FavoriteOrderService::class)->reorder(
            $this->currentUser(),
            $order,
            requireCompleteSet: false,
        );

        $this->dispatch('refresh-sidebar');
    }

    /**
     * Keyboard and touch friendly alternative to dragging a card.
     */
    public function moveFavorite(int $favoriteId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $order = $this->availableFavorites()
            ->pluck('favorite.id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        $index = array_search($favoriteId, $order, true);

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if (! array_key_exists($target, $order)) {
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        $this->reorderFavorites($order);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'favorites' => $this->availableFavorites(),
            'favoritesUrl' => Favorites::getUrl(),
        ];
    }

    /**
     * @return Collection<int, array{favorite: Favorite, target: FavoriteTarget}>
     */
    protected function availableFavorites(): Collection
    {
        $user = $this->currentUser();

        $resolver = app(ResolveFavoriteTarget::class);

        return Favorite::query()
            ->where('user_id', $user->id)
            ->ordered()
            ->with('favoritable')
            ->get()
            ->map(fn (Favorite $favorite): array => [
                'favorite' => $favorite,
                'target' => $resolver($favorite, $user),
            ])
            ->filter(fn (array $row): bool => $row['target']->isAvailable())
            ->take(self::CARD_LIMIT)
            ->values();
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
