<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_respects_task_and_item_visibility_and_limits_users(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Visible Staff']);
        $other = User::factory()->create(['role' => 'staff', 'name' => 'Hidden Staff']);
        $manager = User::factory()->create(['role' => 'manager', 'name' => 'Visible Manager']);
        $client = Client::factory()->create(['name' => 'Acme Search Client']);
        $department = Department::factory()->create();

        $visible = Task::factory()->create([
            'created_by' => $other->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'title' => 'Visible searchable task',
        ]);
        Task::factory()->involvedOnly()->create([
            'created_by' => $other->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'title' => 'Hidden searchable task',
        ]);
        Item::factory()->ownedBy($staff)->create([
            'client_id' => $client->id,
            'title' => 'Owned searchable item',
        ]);
        Item::factory()->ownedBy($other)->create([
            'client_id' => $client->id,
            'title' => 'Other searchable item',
        ]);

        $staffResponse = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/search?q=Search')
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.id', $visible->id)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'Owned searchable item')
            ->assertJsonCount(0, 'users');
        $this->assertSame('Acme Search Client', $staffResponse->json('clients.0.name'));

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/search?q=Visible')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Visible Staff', 'role' => 'staff'])
            ->assertJsonFragment(['name' => 'Visible Manager', 'role' => 'manager']);
    }

    public function test_short_queries_return_an_empty_shape(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/search?q=a')
            ->assertOk()
            ->assertExactJson(['tasks' => [], 'items' => [], 'clients' => [], 'users' => []]);
    }

    public function test_search_escapes_like_wildcards_and_caps_each_result_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        foreach (range(1, 7) as $index) {
            Task::factory()->create([
                'created_by' => $admin->id,
                'client_id' => $client->id,
                'department_id' => $department->id,
                'title' => "Matching task {$index}",
            ]);
        }
        Task::factory()->create([
            'created_by' => $admin->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'title' => 'Literal 100% value',
        ]);
        Task::factory()->create([
            'created_by' => $admin->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'title' => 'Literal 100x value',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/search?q=Matching')
            ->assertOk()
            ->assertJsonCount(5, 'tasks');

        $literal = $this->getJson('/api/search?'.http_build_query(['q' => '100%']))
            ->assertOk();
        $this->assertSame(['Literal 100% value'], collect($literal->json('tasks'))->pluck('title')->all());
    }
}
