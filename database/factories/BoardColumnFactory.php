<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardColumn>
 */
class BoardColumnFactory extends Factory
{
    protected $model = BoardColumn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => fake()->words(2, true),
            'color' => fake()->randomElement(['gray', 'info', 'warning', 'success', 'danger']),
            // Leave the position empty: the model appends the column to its board.
            'position' => null,
            'wip_limit' => null,
        ];
    }
}
