<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'client_id' => Client::factory(),
            'created_by' => User::factory(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'active',
            'is_recurring' => false,
        ];
    }

    public function recurring(string $type = 'yearly', int $interval = 1): static
    {
        return $this->state(fn (): array => [
            'is_recurring' => true,
            'recurrence_type' => $type,
            'recurrence_interval' => $interval,
        ]);
    }

    public function inDepartments(Department ...$departments): static
    {
        return $this->afterCreating(function (Item $item) use ($departments): void {
            $item->departments()->sync(collect($departments)->pluck('id')->all());
        });
    }

    public function ownedBy(User ...$users): static
    {
        return $this->afterCreating(function (Item $item) use ($users): void {
            $item->owners()->sync(collect($users)->pluck('id')->all());
        });
    }
}
