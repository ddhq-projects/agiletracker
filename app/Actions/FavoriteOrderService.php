<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single domain entry point for the personal favorite order. Every view
 * that reorders favorites goes through here, so the stored sequence is always
 * a normalized 1..N list that belongs to exactly one user.
 */
final class FavoriteOrderService
{
    /**
     * Reorder the given favorites. The full page passes every favorite of the
     * user; the dashboard passes only the currently available ones, which keep
     * their slots while the rest of the sequence stays untouched.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(User $user, array $orderedIds, bool $requireCompleteSet = true): void
    {
        DB::transaction(function () use ($user, $orderedIds, $requireCompleteSet): void {
            $ids = array_values(array_map(static fn (int|string $id): int => (int) $id, $orderedIds));

            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'favorites' => 'The new order contains duplicate favorites.',
                ]);
            }

            $current = $this->orderedIds($user);

            if (array_diff($ids, $current) !== []) {
                throw ValidationException::withMessages([
                    'favorites' => 'The new order contains favorites that do not belong to you.',
                ]);
            }

            if ($requireCompleteSet && array_diff($current, $ids) !== []) {
                throw ValidationException::withMessages([
                    'favorites' => 'The new order must contain every favorite.',
                ]);
            }

            if ($ids === []) {
                return;
            }

            $this->persist($user, $this->mergePartialOrder($current, $ids));
        });
    }

    /**
     * Move one favorite to a one-based priority among all favorites of the user.
     */
    public function moveToPriority(User $user, int $favoriteId, int $priority): void
    {
        DB::transaction(function () use ($user, $favoriteId, $priority): void {
            $current = $this->orderedIds($user);

            $index = array_search($favoriteId, $current, true);

            if ($index === false) {
                throw ValidationException::withMessages([
                    'priority' => 'The favorite does not belong to you.',
                ]);
            }

            $total = count($current);

            if ($priority < 1 || $priority > $total) {
                throw ValidationException::withMessages([
                    'priority' => "The priority must be between 1 and {$total}.",
                ]);
            }

            array_splice($current, $index, 1);
            array_splice($current, $priority - 1, 0, [$favoriteId]);

            $this->persist($user, $current);
        });
    }

    /**
     * @return array<int, int>
     */
    public function orderedIds(User $user): array
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->ordered()
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Keep the untouched favorites in their slots and lay the given sequence
     * over the slots the user is allowed to move.
     *
     * @param  array<int, int>  $current
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function mergePartialOrder(array $current, array $ids): array
    {
        $queue = $ids;
        $movable = array_flip($ids);

        $order = [];

        foreach ($current as $id) {
            $order[] = isset($movable[$id]) ? array_shift($queue) : $id;
        }

        return $order;
    }

    /**
     * @param  array<int, int>  $order
     */
    private function persist(User $user, array $order): void
    {
        foreach ($order as $index => $id) {
            DB::table('favorites')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['position' => $index + 1]);
        }
    }
}
