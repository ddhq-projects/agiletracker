<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ItemStatus;
use App\Enums\ItemVisibility;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->boolean(60) ? fake()->sentence(10) : null,
            'is_epic' => false,
            // Items created by the application start restricted; the factory
            // models the historical, public rows unless a test opts in.
            'visibility' => ItemVisibility::Public,
            'due_date' => fake()->boolean(40) ? now()->addDays(rand(1, 30))->toDateString() : null,
            // Leave the position empty: Item assigns the next value in the column on create.
            'position' => null,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn (): array => ['visibility' => ItemVisibility::Restricted]);
    }

    public function epic(): static
    {
        return $this->state(fn () => ['is_epic' => true]);
    }

    public function status(ItemStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function childOf(Item $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assignee_id' => $user->id]);
    }
}
