<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_enforces_the_range_limit_and_validates_filters(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $from = now()->toDateString();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/calendar?from='.$from.'&to='.now()->addDays(63)->toDateString())
            ->assertUnprocessable();
        $this->getJson('/api/calendar?from='.$from.'&to='.now()->addDays(62)->toDateString())
            ->assertOk()
            ->assertJsonStructure(['events']);
        $this->getJson('/api/calendar?from='.$from.'&to='.$from.'&type=unknown')
            ->assertUnprocessable();
    }

    public function test_calendar_only_returns_tasks_and_items_visible_to_the_user(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $colleague = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $due = now()->addDays(4)->toDateString();

        $publicTask = Task::factory()->create([
            'created_by' => $colleague->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $due,
            'title' => 'Visible shared task',
        ]);
        $privateTask = Task::factory()->involvedOnly()->create([
            'created_by' => $colleague->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $due,
            'title' => 'Hidden colleague task',
        ]);
        $privateTask->assignees()->sync([$colleague->id]);
        $ownedItem = Item::factory()->ownedBy($staff)->create([
            'client_id' => $client->id,
            'due_date' => $due,
            'title' => 'My owned item',
        ]);
        Item::factory()->ownedBy($colleague)->create([
            'client_id' => $client->id,
            'due_date' => $due,
            'title' => 'Hidden colleague item',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/calendar?from='.now()->toDateString().'&to='.now()->addDays(14)->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'events');

        $titles = collect($response->json('events'))->pluck('title')->all();
        $this->assertContains('Visible shared task', $titles);
        $this->assertContains('My owned item', $titles);
        $this->assertNotContains('Hidden colleague task', $titles);
        $this->assertNotContains('Hidden colleague item', $titles);
        $this->assertSame('task', $response->json('events.0.type'));
        $this->assertContains($ownedItem->id, collect($response->json('events'))->pluck('id')->all());

        $mine = $this->getJson('/api/calendar?from='.now()->toDateString().'&to='.now()->addDays(14)->toDateString().'&mine=1')
            ->assertOk();
        $this->assertSame(['My owned item'], collect($mine->json('events'))->pluck('title')->all());
    }

    public function test_due_date_updates_require_edit_permission_and_write_audit_values(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $owner = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->create(['role' => 'manager']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $oldDate = now()->addDays(5)->toDateString();
        $newDate = now()->addDays(10)->toDateString();
        $task = Task::factory()->create([
            'created_by' => $owner->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $oldDate,
        ]);
        $task->assignees()->sync([$staff->id]);
        $item = Item::factory()->ownedBy($staff)->create([
            'created_by' => $manager->id,
            'client_id' => $client->id,
            'due_date' => $oldDate,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/tasks/{$task->id}/due-date", ['due_date' => $newDate])
            ->assertOk()
            ->assertJsonPath('data.due_date', $newDate);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $staff->id,
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'due-date-changed',
            'old_values' => json_encode(['due_date' => $oldDate]),
            'new_values' => json_encode(['due_date' => $newDate]),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/items/{$item->id}/due-date", ['due_date' => $newDate])
            ->assertForbidden();
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/items/{$item->id}/due-date", ['due_date' => $newDate])
            ->assertOk()
            ->assertJsonPath('data.due_date', $newDate);
        $this->actingAs($staff, 'sanctum')
            ->patchJson('/api/tasks/999999/due-date', ['due_date' => $newDate])
            ->assertNotFound();
        $this->patchJson("/api/tasks/{$task->id}/due-date", ['due_date' => 'invalid'])->assertUnprocessable();
    }
}
