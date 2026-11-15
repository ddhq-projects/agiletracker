<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardItem;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItem>
 */
class BoardItemFactory extends Factory
{
    protected $model = BoardItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'item_id' => Item::factory(),
            'board_column_id' => null,
            // Leave the position empty: the model appends the card to its column.
            'position' => null,
        ];
    }
}
