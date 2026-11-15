<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * Links any items — tasks or whole projects — to a board. A board is a
 * collection, so an item that is already on it is left where it is instead of
 * being moved to the chosen column.
 */
final class AddItemsToBoard
{
    /**
     * @param  array<int, int|string>  $itemIds
     * @return array{added: int, skipped: int}
     */
    public function __invoke(Board $board, array $itemIds, ?BoardColumn $column = null): array
    {
        $column ??= $board->columns()->first()
            ?? throw new \InvalidArgumentException('A board needs at least one column.');

        $itemIds = Item::query()
            ->whereKey($itemIds)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        if ($itemIds === []) {
            return ['added' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($board, $itemIds, $column): array {
            $existing = $board->boardItems()
                ->whereIn('item_id', $itemIds)
                ->pluck('item_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $newIds = array_values(array_diff($itemIds, $existing));

            $lastPosition = $board->boardItems()
                ->where('board_column_id', $column->getKey())
                ->max('position');

            $position = $lastPosition === null
                ? DecimalPosition::forEmptyColumn()
                : DecimalPosition::after((string) $lastPosition);

            foreach ($newIds as $itemId) {
                BoardItem::create([
                    'board_id' => $board->getKey(),
                    'item_id' => $itemId,
                    'board_column_id' => $column->getKey(),
                    'position' => $position,
                ]);

                $position = DecimalPosition::after($position);
            }

            return [
                'added' => count($newIds),
                'skipped' => count($itemIds) - count($newIds),
            ];
        });
    }
}
