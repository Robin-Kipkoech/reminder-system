<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\ReminderPeriod;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff', array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attributes));
    }

    /**
     * @return array{client: Client, department: Department, period: ReminderPeriod, assignee: User}
     */
    private function fixtures(): array
    {
        return [
            'client' => Client::factory()->create(),
            'department' => Department::factory()->create(),
            'period' => ReminderPeriod::factory()->create(['label' => '7 days before', 'offset_days' => 7]),
            'assignee' => $this->user('staff'),
        ];
    }

    /**
     * @param  array{client: Client, department: Department, assignee: User, period?: ReminderPeriod}  $fixtures
     * @return array<string, mixed>
     */
    private function payload(array $fixtures, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Renew certificates',
            'description' => 'Annual renewal work',
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
            'due_date' => now()->addDays(5)->format('Y-m-d'),
            'visibility' => 'everyone',
            'assignee_ids' => [$fixtures['assignee']->id],
            'reminder_period_ids' => [],
            'is_recurring' => false,
        ], $overrides);
    }

    public function test_staff_can_create_a_task_with_multiple_assignees_and_reminders(): void
    {
        $creator = $this->user('staff');
        $assigneeB = $this->user('manager');
        $fixtures = $this->fixtures();
        $fixtures['assignee'] = $this->user('staff');

        $response = $this->actingAs($creator, 'sanctum')->postJson('/api/tasks', [
            ...$this->payload($fixtures),
            'assignee_ids' => [$fixtures['assignee']->id, $assigneeB->id],
            'reminder_period_ids' => [$fixtures['period']->id],
            // Ignored on create: the server owns status and created_by.
            'status' => 'done',
            'created_by' => $assigneeB->id,
        ])->assertCreated();

        $id = $response->json('data.id');

        $response
            ->assertJsonPath('data.title', 'Renew certificates')
            ->assertJsonPath('data.description', 'Annual renewal work')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.visibility', 'everyone')
            ->assertJsonPath('data.creator.id', $creator->id)
            ->assertJsonPath('data.client.id', $fixtures['client']->id)
            ->assertJsonPath('data.department.id', $fixtures['department']->id)
            ->assertJsonPath('data.due_date', now()->addDays(5)->format('Y-m-d'))
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.days_until_due', 5)
            ->assertJsonPath('data.comments_count', 0)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonCount(2, 'data.assignees')
            ->assertJsonCount(1, 'data.reminder_periods')
            ->assertJsonPath('data.reminder_periods.0.label', '7 days before')
            ->assertJsonPath('data.reminder_periods.0.offset_days', 7);

        $this->assertDatabaseHas('tasks', [
            'id' => $id,
            'status' => 'open',
            'created_by' => $creator->id,
            'visibility' => 'everyone',
        ]);
        $this->assertDatabaseHas('task_assignees', ['task_id' => $id, 'user_id' => $fixtures['assignee']->id]);
        $this->assertDatabaseHas('task_assignees', ['task_id' => $id, 'user_id' => $assigneeB->id]);
        $this->assertDatabaseHas('task_reminder_periods', ['task_id' => $id, 'reminder_period_id' => $fixtures['period']->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $creator->id,
            'entity_type' => 'task',
            'entity_id' => $id,
            'action' => 'created',
        ]);
    }

    public function test_involved_only_tasks_are_hidden_from_outsiders(): void
    {
        $creator = $this->user('staff');
        $assignee = $this->user('staff');
        $outsider = $this->user('staff');
        $admin = $this->user('admin');
        $fixtures = $this->fixtures();

        $task = Task::factory()->involvedOnly()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        $public = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);

        $payload = $this->payload($fixtures);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/tasks/{$task->id}")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/tasks/{$task->id}/comments")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->putJson("/api/tasks/{$task->id}", $payload)->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson("/api/tasks/{$task->id}/status", ['status' => 'done'])->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->deleteJson("/api/tasks/{$task->id}")->assertNotFound();

        $this->actingAs($outsider, 'sanctum')->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id);

        $this->actingAs($assignee, 'sanctum')->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $task->id);

        $this->actingAs($creator, 'sanctum')->getJson("/api/tasks/{$task->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson("/api/tasks/{$task->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/tasks')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_edit_and_delete_permissions_follow_the_rules(): void
    {
        $creator = $this->user('staff');
        $assignee = $this->user('staff');
        $stranger = $this->user('staff');
        $admin = $this->user('admin');
        $fixtures = $this->fixtures();

        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        $payload = $this->payload($fixtures, ['title' => 'Updated title']);

        $this->actingAs($assignee, 'sanctum')->putJson("/api/tasks/{$task->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.can_delete', false);

        $this->actingAs($stranger, 'sanctum')->putJson("/api/tasks/{$task->id}", $payload)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($assignee, 'sanctum')->deleteJson("/api/tasks/{$task->id}")->assertForbidden();

        $this->assertNotSoftDeleted('tasks', ['id' => $task->id]);

        $this->actingAs($creator, 'sanctum')->deleteJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Task deleted.');
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'deleted',
        ]);
    }

    public function test_admin_can_delete_any_task(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('staff');
        $fixtures = $this->fixtures();

        $task = Task::factory()->create([
            'created_by' => $other->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/tasks/{$task->id}")->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_overdue_filter_ignores_done_tasks(): void
    {
        $user = $this->user('staff');

        $overdue = Task::factory()->create([
            'created_by' => $user->id,
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'open',
        ]);
        $doneYesterday = Task::factory()->create([
            'created_by' => $user->id,
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'done',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
        $dueToday = Task::factory()->create([
            'created_by' => $user->id,
            'due_date' => now()->toDateString(),
        ]);
        Task::factory()->create([
            'created_by' => $user->id,
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/tasks?status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id);

        $this->actingAs($user, 'sanctum')->getJson('/api/tasks?status=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $doneYesterday->id);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks/{$overdue->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.days_until_due', -1);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks/{$dueToday->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.days_until_due', 0);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks/{$doneYesterday->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);
    }

    public function test_completing_a_recurring_task_creates_exactly_one_next_occurrence(): void
    {
        $creator = $this->user('staff');
        $assignee = $this->user('staff');
        $fixtures = $this->fixtures();
        $due = now()->addDays(2);

        $task = Task::factory()->recurring('weekly', 1)->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
            'due_date' => $due->toDateString(),
        ]);
        $task->assignees()->sync([$assignee->id]);
        $task->reminderPeriods()->sync([$fixtures['period']->id]);

        $this->actingAs($assignee, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_by.id', $assignee->id)
            ->assertJsonPath('meta.next_task.status', 'open')
            ->assertJsonPath('meta.next_task.due_date', $due->copy()->addWeek()->toDateString())
            ->assertJsonPath('meta.next_task.creator.id', $creator->id)
            ->assertJsonPath('meta.next_task.assignees.0.id', $assignee->id)
            ->assertJsonPath('meta.next_task.reminder_periods.0.id', $fixtures['period']->id);

        // Completing again must not spawn a second occurrence.
        $this->actingAs($assignee, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('meta.next_task', null);

        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame($assignee->id, $task->fresh()->completed_by);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'completed',
        ]);
    }

    public function test_monthly_recurrence_from_january_31st_does_not_overflow(): void
    {
        $creator = $this->user('staff');
        $january31 = Carbon::create(now()->year + 1, 1, 31, 0, 0, 0, 'Africa/Nairobi');

        $task = Task::factory()->recurring('monthly')->create([
            'created_by' => $creator->id,
            'due_date' => $january31->toDateString(),
        ]);

        $this->actingAs($creator, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('meta.next_task.due_date', Carbon::create(now()->year + 1, 2, 28)->toDateString());

        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_recurring_task_skips_occurrences_that_are_already_in_the_past(): void
    {
        $creator = $this->user('staff');

        $task = Task::factory()->recurring('daily', 3)->create([
            'created_by' => $creator->id,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($creator, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('meta.next_task.due_date', now()->addDays(2)->toDateString());
    }

    public function test_deactivated_client_and_department_are_rejected_on_create(): void
    {
        $user = $this->user('staff');
        $fixtures = $this->fixtures();
        $inactiveClient = Client::factory()->inactive()->create();
        $inactiveDepartment = Department::factory()->inactive()->create();
        $suspended = $this->user('staff', ['status' => 'suspended']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, ['client_id' => $inactiveClient->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, ['department_id' => $inactiveDepartment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, ['assignee_ids' => [$suspended->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_ids.0');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_past_due_dates_are_rejected_on_create_but_allowed_on_update(): void
    {
        $creator = $this->user('staff');
        $fixtures = $this->fixtures();

        $this->actingAs($creator, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, ['due_date' => now()->subDay()->format('Y-m-d')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$fixtures['assignee']->id]);

        $this->actingAs($creator, 'sanctum')
            ->putJson("/api/tasks/{$task->id}", $this->payload($fixtures, [
                'due_date' => now()->subDays(3)->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.due_date', now()->subDays(3)->format('Y-m-d'));
    }

    public function test_recurrence_fields_are_validated(): void
    {
        $creator = $this->user('staff');
        $fixtures = $this->fixtures();

        $this->actingAs($creator, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, ['is_recurring' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_type');

        $this->actingAs($creator, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, [
                'is_recurring' => true,
                'recurrence_type' => 'custom_days',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_interval');

        $this->actingAs($creator, 'sanctum')
            ->postJson('/api/tasks', $this->payload($fixtures, [
                'is_recurring' => true,
                'recurrence_type' => 'custom_days',
                'recurrence_interval' => 400,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recurrence_interval');
    }

    public function test_status_changes_are_limited_to_involved_users(): void
    {
        $creator = $this->user('staff');
        $assignee = $this->user('staff');
        $stranger = $this->user('staff');
        $fixtures = $this->fixtures();

        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'open']);

        $this->actingAs($assignee, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($assignee, 'sanctum')->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame($assignee->id, $task->fresh()->completed_by);

        // Moving away from done clears the completion data.
        $this->actingAs($assignee, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.completed_by', null);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'status-changed',
        ]);
    }

    public function test_comments_permissions(): void
    {
        $creator = $this->user('staff');
        $assignee = $this->user('staff');
        $outsider = $this->user('staff');
        $admin = $this->user('admin');
        $fixtures = $this->fixtures();

        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$assignee->id]);

        $created = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Starting today'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Starting today')
            ->assertJsonPath('data.author.id', $creator->id)
            ->assertJsonPath('data.can_delete', true);
        $commentId = $created->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'action' => 'comment-added',
        ]);

        // Anyone who can see the task can read and add comments.
        $this->actingAs($outsider, 'sanctum')->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'I can see this'])
            ->assertCreated();

        // Only the author or an admin may delete.
        $this->actingAs($assignee, 'sanctum')
            ->deleteJson("/api/tasks/{$task->id}/comments/{$commentId}")
            ->assertForbidden();

        $this->actingAs($creator, 'sanctum')
            ->deleteJson("/api/tasks/{$task->id}/comments/{$commentId}")
            ->assertOk()
            ->assertJsonPath('message', 'Comment deleted.');
        $this->assertDatabaseMissing('task_comments', ['id' => $commentId]);

        $adminComment = $this->actingAs($assignee, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Admin should remove this'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/tasks/{$task->id}/comments/{$adminComment}")
            ->assertOk();
        $this->assertDatabaseMissing('task_comments', ['id' => $adminComment]);

        $this->actingAs($creator, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_update_syncs_assignees_and_reminder_periods(): void
    {
        $creator = $this->user('staff');
        $newAssignee = $this->user('staff');
        $fixtures = $this->fixtures();

        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->assignees()->sync([$fixtures['assignee']->id]);
        $task->reminderPeriods()->sync([$fixtures['period']->id]);

        // A deactivated period stays valid for periods already attached to the task.
        $fixtures['period']->update(['is_active' => false]);

        $this->actingAs($creator, 'sanctum')->putJson("/api/tasks/{$task->id}", $this->payload($fixtures, [
            'assignee_ids' => [$newAssignee->id],
            'reminder_period_ids' => [$fixtures['period']->id],
        ]))->assertOk();

        $this->assertDatabaseHas('task_assignees', ['task_id' => $task->id, 'user_id' => $newAssignee->id]);
        $this->assertDatabaseMissing('task_assignees', ['task_id' => $task->id, 'user_id' => $fixtures['assignee']->id]);
        $this->assertDatabaseHas('task_reminder_periods', [
            'task_id' => $task->id,
            'reminder_period_id' => $fixtures['period']->id,
        ]);

        // A deactivated period that is not attached is still rejected.
        $otherPeriod = ReminderPeriod::factory()->inactive()->create();

        $this->actingAs($creator, 'sanctum')->putJson("/api/tasks/{$task->id}", $this->payload($fixtures, [
            'reminder_period_ids' => [$otherPeriod->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('reminder_period_ids.0');
    }

    public function test_list_supports_filters_pagination_meta_and_admin_only_trashed(): void
    {
        $user = $this->user('staff');
        $other = $this->user('staff');
        $admin = $this->user('admin');
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $department = Department::factory()->create();

        $mine = Task::factory()->create([
            'created_by' => $user->id,
            'client_id' => $clientA->id,
            'department_id' => $department->id,
            'title' => 'Alpha certificates',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        $mine->assignees()->sync([$other->id]);

        $theirs = Task::factory()->create([
            'created_by' => $other->id,
            'client_id' => $clientB->id,
            'department_id' => $department->id,
            'title' => 'Beta renewals',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $deleted = Task::factory()->create([
            'created_by' => $user->id,
            'client_id' => $clientA->id,
            'department_id' => $department->id,
        ]);
        $deleted->delete();

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'title', 'description', 'department', 'client', 'creator', 'assignees',
                    'reminder_periods', 'due_date', 'status', 'visibility', 'is_recurring',
                    'recurrence_type', 'recurrence_interval', 'is_overdue', 'days_until_due',
                    'completed_at', 'completed_by', 'comments_count', 'can_edit', 'can_delete',
                    'created_at', 'deleted_at',
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('data.0.id', $mine->id); // default sort: due_date asc

        $this->assertSame(1, $list->json('meta.current_page'));

        $this->actingAs($user, 'sanctum')->getJson('/api/tasks?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks?assignee_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks?client_id={$clientB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);

        $this->actingAs($user, 'sanctum')->getJson("/api/tasks?department_id={$department->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($user, 'sanctum')->getJson('/api/tasks?search=Beta')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks?due_from='.now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tasks?due_to='.now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        // with_trashed is ignored for everyone but admins.
        $this->actingAs($user, 'sanctum')->getJson('/api/tasks?with_trashed=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/api/tasks?with_trashed=1')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_comment_reads_require_task_visibility(): void
    {
        $creator = $this->user('staff');
        $outsider = $this->user('staff');
        $fixtures = $this->fixtures();

        $task = Task::factory()->involvedOnly()->create([
            'created_by' => $creator->id,
            'client_id' => $fixtures['client']->id,
            'department_id' => $fixtures['department']->id,
        ]);
        $task->comments()->create(['user_id' => $creator->id, 'body' => 'Secret update']);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/tasks/{$task->id}/comments")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Sneaky'])
            ->assertNotFound();

        $this->actingAs($creator, 'sanctum')->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Secret update');

        $this->assertDatabaseCount('task_comments', 1);
    }

    public function test_tasks_require_authentication(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->postJson('/api/tasks', [])->assertUnauthorized();
    }
}
