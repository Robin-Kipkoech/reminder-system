<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function taskPayload(Client $client, Department $department, array $assignees, array $overrides = []): array
    {
        return [
            'title' => 'Task for notifications',
            'description' => '',
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'visibility' => 'everyone',
            'assignee_ids' => $assignees,
            'reminder_period_ids' => [],
            'is_recurring' => false,
            ...$overrides,
        ];
    }

    private function itemPayload(Client $client, Department $department, array $owners, array $overrides = []): array
    {
        return [
            'title' => 'Item for notifications',
            'description' => '',
            'client_id' => $client->id,
            'department_ids' => [$department->id],
            'owner_ids' => $owners,
            'due_date' => now()->addDays(30)->toDateString(),
            'reminder_period_ids' => [],
            'is_recurring' => false,
            ...$overrides,
        ];
    }

    public function test_task_assignment_and_new_assignees_are_notified_without_self_notifications(): void
    {
        $actor = User::factory()->create(['role' => 'staff']);
        $first = User::factory()->create(['role' => 'staff']);
        $added = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/tasks', $this->taskPayload(
            $client,
            $department,
            [$actor->id, $first->id],
        ))->assertCreated();
        $taskId = $created->json('data.id');

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->id,
            'type' => 'assigned',
            'source_type' => 'task',
            'source_id' => $taskId,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $actor->id]);

        $this->putJson("/api/tasks/{$taskId}", $this->taskPayload(
            $client,
            $department,
            [$actor->id, $first->id, $added->id],
        ))->assertOk();

        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $added->id,
            'type' => 'assigned',
            'source_id' => $taskId,
        ]);
    }

    public function test_item_creation_and_new_owners_are_notified(): void
    {
        $actor = User::factory()->create(['role' => 'manager']);
        $first = User::factory()->create(['role' => 'staff']);
        $added = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/items', $this->itemPayload(
            $client,
            $department,
            [$actor->id, $first->id],
        ))->assertCreated();
        $itemId = $response->json('data.id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $first->id,
            'type' => 'assigned',
            'source_type' => 'item',
            'source_id' => $itemId,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $actor->id]);

        $this->putJson("/api/items/{$itemId}", $this->itemPayload(
            $client,
            $department,
            [$actor->id, $first->id, $added->id],
        ))->assertOk();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $added->id,
            'type' => 'assigned',
            'source_type' => 'item',
            'source_id' => $itemId,
        ]);
    }

    public function test_comments_notify_participants_and_visible_mentions_with_separate_types(): void
    {
        $creator = User::factory()->create(['role' => 'staff']);
        $actor = User::factory()->create(['role' => 'staff']);
        $assignee = User::factory()->create(['role' => 'staff']);
        $commentRecipient = User::factory()->create(['role' => 'staff']);
        $outsider = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $task = Task::factory()->involvedOnly()->create([
            'created_by' => $creator->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
        ]);
        $task->assignees()->sync([$actor->id, $assignee->id, $commentRecipient->id]);

        $this->actingAs($actor, 'sanctum')->postJson("/api/tasks/{$task->id}/comments", [
            'body' => "Please review @[{$assignee->name}](user:{$assignee->id}) and @[{$outsider->name}](user:{$outsider->id})",
        ])->assertCreated();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'comment',
            'source_id' => $task->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $commentRecipient->id,
            'type' => 'comment',
            'source_id' => $task->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $assignee->id,
            'type' => 'mention',
            'source_id' => $task->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $assignee->id, 'type' => 'comment']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $outsider->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $actor->id]);
    }

    public function test_completing_a_task_notifies_its_creator(): void
    {
        $creator = User::factory()->create(['role' => 'staff']);
        $assignee = User::factory()->create(['role' => 'staff']);
        $task = Task::factory()->create(['created_by' => $creator->id]);
        $task->assignees()->sync([$assignee->id]);

        $this->actingAs($assignee, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'completed',
            'source_type' => 'task',
            'source_id' => $task->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $assignee->id]);
    }

    public function test_notification_endpoints_are_private_and_support_read_operations(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $owned = UserNotification::query()->create([
            'user_id' => $owner->id,
            'type' => 'system',
            'title' => 'Owned notification',
            'message' => 'Hello',
        ]);
        $foreign = UserNotification::query()->create([
            'user_id' => $other->id,
            'type' => 'system',
            'title' => 'Private notification',
            'message' => 'Secret',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/notifications?per_page=8')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Owned notification')
            ->assertJsonPath('meta.per_page', 8);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 1);
        $this->postJson("/api/notifications/{$foreign->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/notifications/{$owned->id}/read")->assertOk()
            ->assertJsonPath('data.id', $owned->id);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->deleteJson("/api/notifications/{$owned->id}")->assertOk();
    }
}
