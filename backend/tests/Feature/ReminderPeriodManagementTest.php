<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\ReminderPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReminderPeriodManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeTask(int $createdBy): int
    {
        return DB::table('tasks')->insertGetId([
            'title' => 'Sample task',
            'department_id' => Department::factory()->create()->id,
            'client_id' => Client::factory()->create()->id,
            'created_by' => $createdBy,
            'due_date' => now()->toDateString(),
            'visibility' => 'everyone',
            'status' => 'open',
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeItem(int $createdBy): int
    {
        return DB::table('items')->insertGetId([
            'title' => 'Sample item',
            'client_id' => Client::factory()->create()->id,
            'created_by' => $createdBy,
            'due_date' => now()->toDateString(),
            'status' => 'active',
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_crud_reminder_periods_and_every_action_is_audited(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/reminder-periods', [
            'label' => '5 days before',
            'offset_days' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.label', '5 days before')
            ->assertJsonPath('data.offset_days', 5)
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->getJson('/api/reminder-periods')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $id, 'label' => '5 days before']);

        $this->getJson("/api/reminder-periods/{$id}")
            ->assertOk()
            ->assertJsonPath('data.offset_days', 5);

        $this->putJson("/api/reminder-periods/{$id}", ['label' => 'Five days before'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Five days before');

        $this->postJson("/api/reminder-periods/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson("/api/reminder-periods/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->deleteJson("/api/reminder-periods/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Reminder period deleted.');

        $this->assertDatabaseMissing('reminder_periods', ['id' => $id]);

        foreach (['created', 'updated', 'activated', 'deactivated', 'deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $admin->id,
                'entity_type' => 'reminder_period',
                'entity_id' => $id,
                'action' => $action,
            ]);
        }
    }

    public function test_managers_and_staff_can_read_but_cannot_write_reminder_periods(): void
    {
        $period = ReminderPeriod::factory()->create(['label' => 'On due day', 'offset_days' => 0]);

        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');

            $this->getJson('/api/reminder-periods')->assertOk()->assertJsonFragment(['label' => 'On due day']);

            $this->getJson('/api/reminder-periods/options')
                ->assertOk()
                ->assertJsonFragment(['id' => $period->id, 'label' => 'On due day', 'offset_days' => 0])
                ->assertJsonPath('data.0.id', $period->id)
                ->assertJsonPath('data.0.label', 'On due day')
                ->assertJsonPath('data.0.offset_days', 0);

            $this->getJson("/api/reminder-periods/{$period->id}")->assertOk();

            $this->postJson('/api/reminder-periods', ['label' => 'Blocked', 'offset_days' => 42])->assertForbidden();
            $this->putJson("/api/reminder-periods/{$period->id}", ['label' => 'Blocked'])->assertForbidden();
            $this->postJson("/api/reminder-periods/{$period->id}/activate")->assertForbidden();
            $this->postJson("/api/reminder-periods/{$period->id}/deactivate")->assertForbidden();
            $this->deleteJson("/api/reminder-periods/{$period->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('reminder_periods', ['id' => $period->id, 'label' => 'On due day']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_offset_days_and_overlong_labels_are_rejected(): void
    {
        ReminderPeriod::factory()->create(['label' => '3 days before', 'offset_days' => 3]);

        $this->actingAs($this->user('admin'), 'sanctum');

        $this->postJson('/api/reminder-periods', ['label' => 'Another label', 'offset_days' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offset_days');

        $this->postJson('/api/reminder-periods', ['label' => str_repeat('a', 101), 'offset_days' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');

        $this->postJson('/api/reminder-periods', ['label' => 'Too many days', 'offset_days' => 366])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offset_days');

        $this->assertDatabaseCount('reminder_periods', 1);
    }

    public function test_reminder_period_in_use_by_tasks_or_items_cannot_be_hard_deleted(): void
    {
        $admin = $this->user('admin');
        $period = ReminderPeriod::factory()->create();
        $taskId = $this->makeTask($admin->id);

        DB::table('task_reminder_periods')->insert([
            'task_id' => $taskId,
            'reminder_period_id' => $period->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/reminder-periods/{$period->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reminder_period')
            ->assertJsonPath('errors.reminder_period.0', 'This reminder period is in use. Deactivate it instead.');

        $this->assertDatabaseHas('reminder_periods', ['id' => $period->id]);

        // Deactivating stays available.
        $this->postJson("/api/reminder-periods/{$period->id}/deactivate")->assertOk();

        DB::table('task_reminder_periods')->delete();

        $itemId = $this->makeItem($admin->id);
        DB::table('item_reminder_periods')->insert([
            'item_id' => $itemId,
            'reminder_period_id' => $period->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/reminder-periods/{$period->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reminder_period');

        $this->assertDatabaseHas('reminder_periods', ['id' => $period->id]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unused_reminder_period_can_be_deleted_and_deactivated_ones_leave_options(): void
    {
        $admin = $this->user('admin');
        $unused = ReminderPeriod::factory()->create(['label' => '10 days before', 'offset_days' => 10]);

        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/reminder-periods/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('reminder_periods', ['id' => $unused->id]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'reminder_period',
            'entity_id' => $unused->id,
            'action' => 'deleted',
        ]);

        ReminderPeriod::factory()->create(['label' => '7 days before', 'offset_days' => 7]);
        $deactivated = ReminderPeriod::factory()->inactive()->create(['label' => '2 days before', 'offset_days' => 2]);
        $onDueDay = ReminderPeriod::factory()->create(['label' => 'On due day', 'offset_days' => 0]);

        $this->getJson('/api/reminder-periods/options')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', '7 days before')
            ->assertJsonPath('data.0.offset_days', 7)
            ->assertJsonPath('data.1.label', 'On due day')
            ->assertJsonPath('data.1.offset_days', 0);

        $this->getJson("/api/reminder-periods/{$deactivated->id}")->assertOk();
    }
}
