<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

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
            'department_id' => Department::factory(),
            'client_id' => Client::factory(),
            'created_by' => User::factory(),
            'due_date' => now()->addDays(3)->toDateString(),
            'visibility' => 'everyone',
            'status' => 'open',
            'is_recurring' => false,
        ];
    }

    public function involvedOnly(): static
    {
        return $this->state(fn (): array => ['visibility' => 'involved_only']);
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => 'done',
            'completed_at' => now(),
            'completed_by' => User::factory(),
        ]);
    }

    public function recurring(string $type = 'weekly', int $interval = 1): static
    {
        return $this->state(fn (): array => [
            'is_recurring' => true,
            'recurrence_type' => $type,
            'recurrence_interval' => $interval,
        ]);
    }

    public function assignedTo(User ...$users): static
    {
        return $this->afterCreating(function (Task $task) use ($users): void {
            $task->assignees()->sync(collect($users)->pluck('id')->all());
        });
    }
}
