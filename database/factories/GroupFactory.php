<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GroupType;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->boolean(40) ? fake()->sentence() : null,
            'parent_id' => null,
            'type' => GroupType::Other,
        ];
    }

    public function type(GroupType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function project(): static
    {
        return $this->type(GroupType::Project);
    }

    public function team(): static
    {
        return $this->type(GroupType::Team);
    }

    public function childOf(Group $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }
}
