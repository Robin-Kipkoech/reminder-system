<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\ReminderPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff', array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attributes));
    }

    /**
     * @return array{client: Client, department: Department, other_department: Department, period: ReminderPeriod, owner: User}
     */
    private function fixtures(): array
    {
        return [
            'client' => Client::factory()->create(),
            'department' => Department::factory()->create(),
            'other_department' => Department::factory()->create(),
            'period' => ReminderPeriod::factory()->create(['label' => '30 days before', 'offset_days' => 30]),
            'owner' => $this->user('staff'),
        ];
    }

    /**
     * @param  array{client: Client, department: Department, other_department?: Department, period?: ReminderPeriod, owner: User}  $fixtures
     * @return array<string, mixed>
     */
    private function payload(array $fixtures, array $overrides = []): array
    {
        return array_merge([
            'title' => 'SSL certificate',
            'description' => 'Wildcard certificate renewal',
            'client_id' => $fixtures['client']->id,
            'department_ids' => [$fixtures['department']->id],
            'owner_ids' => [$fixtures['owner']->id],
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'reminder_period_ids' => [],
            'is_recurring' => false,
        ], $overrides);
    }

    public function test_admin_and_manager_can_create_items_with_several_departments_and_owners(): void
    {
        $admin = $this->user('admin');
        $manager = $this->user('manager');
        $ownerB = $this->user('manager');
        $fixtures = $this->fixtures();

        foreach ([$admin, $manager] as $creator) {
            $response = $this->actingAs($creator, 'sanctum')->postJson('/api/items', $this->payload($fixtures, [
                'department_ids' => [$fixtures['department']->id, $fixtures['other_department']->id],
                'owner_ids' => [$fixtures['owner']->id, $ownerB->id],
                'reminder_period_ids' => [$fixtures['period']->id],
                'due_date' => now()->subDays(2)->format('Y-m-d'),
                // Ignored on create: the server owns status and created_by.
                'status' => 'expired',
                'created_by' => $ownerB->id,
            ]))->assertCreated();

            $id = $response->json('data.id');

            $response
                ->assertJsonPath('data.title', 'SSL certificate')
                ->assertJsonPath('data.description', 'Wildcard certificate renewal')
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.client.id', $fixtures['client']->id)
                ->assertJsonPath('data.creator.id', $creator->id)
                ->assertJsonPath('data.due_date', now()->subDays(2)->format('Y-m-d'))
                ->assertJsonPath('data.is_recurring', false)
                ->assertJsonPath('data.recurrence_type', null)
                ->assertJsonPath('data.recurrence_interval', null)
                ->assertJsonPath('data.suggested_renewal_date', null)
                ->assertJsonPath('data.last_renewed_at', null)
                ->assertJsonPath('data.is_overdue', true)
                ->assertJsonPath('data.days_until_due', -2)
                ->assertJsonPath('data.can_edit', true)
                ->assertJsonPath('data.can_delete', true)
                ->assertJsonPath('data.can_renew', true)
                ->assertJsonCount(2, 'data.departments')
                ->assertJsonCount(2, 'data.owners')
                ->assertJsonCount(1, 'data.reminder_periods')
                ->assertJsonPath('data.reminder_periods.0.label', '30 days before')
                ->assertJsonPath('data.reminder_periods.0.offset_days', 30);

            $this->assertDatabaseHas('items', [
                'id' => $id,
                'status' => 'active',
                'created_by' => $creator->id,
                'client_id' => $fixtures['client']->id,
            ]);
            $this->assertDatabaseHas('item_departments', ['item_id' => $id, 'department_id' => $fixtures['department']->id]);
            $this->assertDatabaseHas('item_departments', ['item_id' => $id, 'department_id' => $fixtures['other_department']->id]);
            $this->assertDatabaseHas('item_owners', ['item_id' => $id, 'user_id' => $fixtures['owner']->id]);
            $this->assertDatabaseHas('item_owners', ['item_id' => $id, 'user_id' => $ownerB->id]);
            $this->assertDatabaseHas('item_reminder_periods', ['item_id' => $id, 'reminder_period_id' => $fixtures['period']->id]);
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $creator->id,
                'entity_type' => 'item',
                'entity_id' => $id,
                'action' => 'created',
            ]);

            $item = Item::withTrashed()->findOrFail($id);
            $item->forceDelete();
        }

        $this->assertDatabaseCount('items', 0);
    }

    public function test_staff_cannot_create_edit_delete_or_renew(): void
    {
        $staff = $this->user('staff');
        $fixtures = $this->fixtures();

        $item = Item::factory()
            ->ownedBy($staff)
            ->inDepartments($fixtures['department'])
            ->create(['client_id' => $fixtures['client']->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/items', $this->payload($fixtures))
            ->assertForbidden();

        // The item is visible, so the refusal is a 403.
        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/items/{$item->id}", $this->payload($fixtures, ['title' => 'Nope']))
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/items/{$item->id}")
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/items/{$item->id}/renew", ['due_date' => now()->addYear()->format('Y-m-d')])
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/items/{$item->id}/status", ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->id, 'status' => 'active', 'title' => $item->title]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_only_see_items_they_own_and_get_404_for_others(): void
    {
        $staff = $this->user('staff');
        $outsider = $this->user('staff');
        $manager = $this->user('manager');
        $admin = $this->user('admin');
        $fixtures = $this->fixtures();

        $mine = Item::factory()
            ->ownedBy($staff)
            ->inDepartments($fixtures['department'])
            ->create(['client_id' => $fixtures['client']->id]);

        $theirs = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create(['client_id' => $fixtures['client']->id]);

        $this->actingAs($staff, 'sanctum')->getJson('/api/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.0.can_renew', false);

        // Invisible items are a 404, so their existence is not leaked.
        $this->actingAs($staff, 'sanctum')->getJson("/api/items/{$theirs->id}")->assertNotFound();
        $this->actingAs($staff, 'sanctum')->putJson("/api/items/{$theirs->id}", $this->payload($fixtures))->assertNotFound();
        $this->actingAs($staff, 'sanctum')->deleteJson("/api/items/{$theirs->id}")->assertNotFound();
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/items/{$theirs->id}/renew", ['due_date' => now()->addYear()->format('Y-m-d')])
            ->assertNotFound();
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/items/{$theirs->id}/status", ['status' => 'cancelled'])
            ->assertNotFound();

        $this->actingAs($staff, 'sanctum')->getJson("/api/items/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);

        // Managers and admins see every item.
        $this->actingAs($manager, 'sanctum')->getJson('/api/items')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/items')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'sanctum')->getJson("/api/items/{$theirs->id}")->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/items/{$theirs->id}", $this->payload($fixtures, ['title' => 'Manager edit']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Manager edit')
            ->assertJsonPath('data.creator.id', $theirs->created_by);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'item',
            'entity_id' => $theirs->id,
            'action' => 'updated',
            'user_id' => $manager->id,
        ]);
    }

    public function test_renew_moves_the_due_date_and_rejects_dates_that_are_not_later(): void
    {
        $manager = $this->user('manager');
        $fixtures = $this->fixtures();
        $due = Carbon::create(2026, 1, 31, 0, 0, 0, 'Africa/Nairobi');
        $newDue = $due->copy()->addYear()->toDateString();

        $item = Item::factory()->recurring('yearly')
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => $due->toDateString(),
                'status' => 'expired',
            ]);

        $this->actingAs($manager, 'sanctum')->postJson("/api/items/{$item->id}/renew", [
            'due_date' => $newDue,
        ])
            ->assertOk()
            ->assertJsonPath('data.due_date', $newDue)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.suggested_renewal_date', $due->copy()->addYear()->addYear()->toDateString());

        $item->refresh();
        $this->assertSame($newDue, $item->due_date->toDateString());
        $this->assertSame('active', $item->status);
        $this->assertNotNull($item->last_renewed_at);

        $renewal = json_decode((string) \DB::table('audit_logs')
            ->where('entity_type', 'item')
            ->where('entity_id', $item->id)
            ->where('action', 'renewed')
            ->value('old_values'), true);

        $this->assertSame($due->toDateString(), $renewal['due_date']);
        $this->assertSame('expired', $renewal['status']);
        $this->assertSame($newDue, json_decode((string) \DB::table('audit_logs')
            ->where('entity_type', 'item')
            ->where('entity_id', $item->id)
            ->where('action', 'renewed')
            ->value('new_values'), true)['due_date']);

        // The same day, an earlier date and a missing date are all rejected.
        foreach ([$due->toDateString(), $due->copy()->subDay()->toDateString()] as $rejected) {
            $this->actingAs($manager, 'sanctum')
                ->postJson("/api/items/{$item->id}/renew", ['due_date' => $rejected])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('due_date');
        }

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/items/{$item->id}/renew", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/items/{$item->id}/renew", ['due_date' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->assertSame($newDue, $item->fresh()->due_date->toDateString());
    }

    public function test_suggested_renewal_date_for_monthly_items_from_january_31st(): void
    {
        $manager = $this->user('manager');
        $fixtures = $this->fixtures();

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/items', $this->payload($fixtures, [
            'due_date' => '2027-01-31',
            'is_recurring' => true,
            'recurrence_type' => 'monthly',
        ]))->assertCreated();

        // 2027-02-28: month addition never overflows into March.
        $response->assertJsonPath('data.suggested_renewal_date', '2027-02-28');

        $quarterly = $this->actingAs($manager, 'sanctum')->postJson('/api/items', $this->payload($fixtures, [
            'due_date' => '2027-01-31',
            'is_recurring' => true,
            'recurrence_type' => 'quarterly',
        ]))->assertCreated();

        $quarterly->assertJsonPath('data.suggested_renewal_date', '2027-04-30');

        $custom = $this->actingAs($manager, 'sanctum')->postJson('/api/items', $this->payload($fixtures, [
            'due_date' => '2027-01-31',
            'is_recurring' => true,
            'recurrence_type' => 'custom_days',
            'recurrence_interval' => 10,
        ]))->assertCreated();

        $custom->assertJsonPath('data.recurrence_interval', 10)
            ->assertJsonPath('data.suggested_renewal_date', '2027-02-10');

        // A due date far in the past keeps rolling forward to today or later.
        // Monthly steps are applied to the due date, so a Jan 31 start moves by whole
        // months (Jan 31 -> Feb 28) instead of being re-anchored on today's day.
        $rolling = $this->actingAs($manager, 'sanctum')->postJson('/api/items', $this->payload($fixtures, [
            'due_date' => now()->subYear()->startOfMonth()->addDays(30)->toDateString(),
            'is_recurring' => true,
            'recurrence_type' => 'monthly',
        ]))->assertCreated();

        $expected = now()->subYear()->startOfMonth()->addDays(30);

        do {
            $expected = $expected->addMonthNoOverflow();
        } while ($expected->lessThan(now()));

        $rolling->assertJsonPath('data.suggested_renewal_date', $expected->toDateString());
    }

    public function test_overdue_and_department_filters(): void
    {
        $manager = $this->user('manager');
        $fixtures = $this->fixtures();

        $overdue = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => now()->subDay()->toDateString(),
                'status' => 'active',
                'title' => 'Expired firewall licence',
            ]);

        $dueToday = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['other_department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => now()->toDateString(),
                'title' => 'Renew today',
            ]);

        $cancelledPast = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['other_department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => now()->subDays(3)->toDateString(),
                'status' => 'cancelled',
                'title' => 'Dropped contract',
            ]);

        $expiredPast = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => now()->subDays(5)->toDateString(),
                'status' => 'expired',
                'title' => 'Lapsed licence',
            ]);

        $future = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create([
                'client_id' => $fixtures['client']->id,
                'due_date' => now()->addDays(10)->toDateString(),
                'title' => 'Upcoming renewal',
            ]);

        // overdue = active only, and due today is not overdue.
        $this->actingAs($manager, 'sanctum')->getJson('/api/items?status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id);

        $this->actingAs($manager, 'sanctum')->getJson('/api/items?status=active')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($manager, 'sanctum')->getJson("/api/items/{$overdue->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.days_until_due', -1);

        $this->actingAs($manager, 'sanctum')->getJson("/api/items/{$dueToday->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.days_until_due', 0);

        $this->actingAs($manager, 'sanctum')->getJson("/api/items/{$cancelledPast->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);

        // department filter matches any attached department.
        $this->actingAs($manager, 'sanctum')->getJson("/api/items?department_id={$fixtures['department']->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/items?department_id={$fixtures['other_department']->id}&status=cancelled")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cancelledPast->id);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/items?client_id={$fixtures['client']->id}&sort=title&direction=desc")
            ->assertOk()
            ->assertJsonPath('data.0.id', $future->id);

        $this->assertNotNull($expiredPast->id);
    }

    public function test_a_deactivated_client_or_department_is_rejected_on_create(): void
    {
        $manager = $this->user('manager');
        $fixtures = $this->fixtures();
        $inactiveClient = Client::factory()->inactive()->create();
        $inactiveDepartment = Department::factory()->inactive()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/items', $this->payload($fixtures, ['client_id' => $inactiveClient->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/items', $this->payload($fixtures, [
                'department_ids' => [$fixtures['department']->id, $inactiveDepartment->id],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_ids.1');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_list_supports_pagination_meta_trashed_and_staff_scope(): void
    {
        $staff = $this->user('staff');
        $admin = $this->user('admin');
        $fixtures = $this->fixtures();

        $mine = Item::factory()->ownedBy($staff)->create([
            'client_id' => $fixtures['client']->id,
            'due_date' => now()->addDays(2)->toDateString(),
            'title' => 'Alpha licence',
        ]);
        $theirs = Item::factory()->ownedBy($fixtures['owner'])->create([
            'client_id' => $fixtures['client']->id,
            'due_date' => now()->addDays(10)->toDateString(),
            'title' => 'Beta service',
        ]);

        $deleted = Item::factory()->ownedBy($staff)->create(['client_id' => $fixtures['client']->id]);
        $deleted->delete();

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/items')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'title', 'description', 'client', 'departments', 'owners', 'creator',
                    'reminder_periods', 'due_date', 'status', 'is_recurring', 'recurrence_type',
                    'recurrence_interval', 'is_overdue', 'days_until_due', 'suggested_renewal_date',
                    'last_renewed_at', 'can_edit', 'can_delete', 'can_renew', 'created_at', 'deleted_at',
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.id', $mine->id); // default sort: due_date asc

        $this->assertSame(1, $list->json('meta.current_page'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/items?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        // with_trashed is ignored for everyone but admins.
        $this->actingAs($staff, 'sanctum')->getJson('/api/items?with_trashed=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/api/items?with_trashed=1')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/items?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/items?owner_id={$fixtures['owner']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);

        $this->actingAs($staff, 'sanctum')->getJson('/api/items?mine=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_update_syncs_relations_and_rejects_inactive_reminder_periods(): void
    {
        $manager = $this->user('manager');
        $newOwner = $this->user('staff');
        $fixtures = $this->fixtures();

        $item = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create(['client_id' => $fixtures['client']->id]);

        $item->reminderPeriods()->sync([$fixtures['period']->id]);

        // A deactivated period stays valid for periods already attached to the item.
        $fixtures['period']->update(['is_active' => false]);

        $this->actingAs($manager, 'sanctum')->putJson("/api/items/{$item->id}", $this->payload($fixtures, [
            'department_ids' => [$fixtures['other_department']->id],
            'owner_ids' => [$newOwner->id],
            'reminder_period_ids' => [$fixtures['period']->id],
        ]))->assertOk();

        $this->assertDatabaseHas('item_departments', ['item_id' => $item->id, 'department_id' => $fixtures['other_department']->id]);
        $this->assertDatabaseMissing('item_departments', ['item_id' => $item->id, 'department_id' => $fixtures['department']->id]);
        $this->assertDatabaseHas('item_owners', ['item_id' => $item->id, 'user_id' => $newOwner->id]);
        $this->assertDatabaseMissing('item_owners', ['item_id' => $item->id, 'user_id' => $fixtures['owner']->id]);
        $this->assertDatabaseHas('item_reminder_periods', [
            'item_id' => $item->id,
            'reminder_period_id' => $fixtures['period']->id,
        ]);

        // A deactivated period that is not attached is still rejected.
        $otherPeriod = ReminderPeriod::factory()->inactive()->create();

        $this->actingAs($manager, 'sanctum')->putJson("/api/items/{$item->id}", $this->payload($fixtures, [
            'reminder_period_ids' => [$otherPeriod->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('reminder_period_ids.0');

        // Empty and missing department or owner lists are rejected.
        $this->actingAs($manager, 'sanctum')->putJson("/api/items/{$item->id}", $this->payload($fixtures, [
            'department_ids' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors('department_ids');

        $this->actingAs($manager, 'sanctum')->putJson("/api/items/{$item->id}", $this->payload($fixtures, [
            'owner_ids' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors('owner_ids');
    }

    public function test_status_changes_and_delete_are_audited(): void
    {
        $manager = $this->user('manager');
        $fixtures = $this->fixtures();

        $item = Item::factory()
            ->ownedBy($fixtures['owner'])
            ->inDepartments($fixtures['department'])
            ->create(['client_id' => $fixtures['client']->id]);

        $this->actingAs($manager, 'sanctum')->postJson("/api/items/{$item->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // "renewed" is owned by the renew endpoint.
        $this->actingAs($manager, 'sanctum')->postJson("/api/items/{$item->id}/status", ['status' => 'renewed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($manager, 'sanctum')->postJson("/api/items/{$item->id}/status", ['status' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Item deleted.');

        $this->assertSoftDeleted('items', ['id' => $item->id]);

        foreach (['status-changed', 'deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'entity_type' => 'item',
                'entity_id' => $item->id,
                'action' => $action,
            ]);
        }
    }

    public function test_items_require_authentication(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();
        $this->postJson('/api/items', [])->assertUnauthorized();
    }
}
