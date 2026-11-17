<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_staff_dashboard_is_scoped_to_their_own_work(): void
    {
        $staff = $this->user('staff');
        $colleague = $this->user('staff');
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $today = Carbon::today();

        $own = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->addDays(2)->toDateString(),
            'title' => 'My own task',
        ]);
        $own->assignees()->sync([$staff->id]);

        // A colleague's private task must not leak into any part of the payload.
        $hidden = Task::factory()->involvedOnly()->create([
            'created_by' => $colleague->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->subDays(3)->toDateString(),
            'title' => 'Colleague private task',
        ]);
        $hidden->assignees()->sync([$colleague->id]);

        // A shared task that is not mine is visible but is not "my work".
        $shared = Task::factory()->create([
            'created_by' => $colleague->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->subDay()->toDateString(),
            'title' => 'Shared overdue task',
        ]);

        $ownedItem = Item::factory()->ownedBy($staff)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->addDays(5)->toDateString(),
            'title' => 'My licence',
        ]);

        Item::factory()->ownedBy($colleague)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->subDays(2)->toDateString(),
            'title' => 'Their licence',
        ]);

        $response = $this->actingAs($staff, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonStructure([
            'stats' => ['overdue_tasks', 'due_today', 'due_this_week', 'my_open_tasks', 'overdue_items', 'items_expiring_30'],
            'my_tasks' => [['id', 'title', 'due_date', 'days_until_due', 'status', 'is_overdue', 'client' => ['id', 'name']]],
            'upcoming' => [['type', 'id', 'title', 'client_name', 'due_date', 'days_until_due', 'status', 'is_overdue']],
        ]);

        // Only my own assigned task counts as my work; the shared overdue task is
        // visible in the stats but not "mine".
        $response->assertJsonPath('stats.overdue_tasks', 1)
            ->assertJsonPath('stats.my_open_tasks', 1)
            ->assertJsonPath('stats.due_today', 0)
            ->assertJsonPath('stats.due_this_week', 1)
            ->assertJsonPath('stats.overdue_items', 0)
            ->assertJsonPath('stats.items_expiring_30', 1)
            ->assertJsonCount(1, 'my_tasks')
            ->assertJsonPath('my_tasks.0.id', $own->id)
            ->assertJsonPath('my_tasks.0.days_until_due', 2)
            ->assertJsonPath('my_tasks.0.is_overdue', false);

        // Manager-only sections are null for staff.
        $response->assertJsonPath('items_expiring', null)
            ->assertJsonPath('workload', null)
            ->assertJsonPath('by_client', null)
            ->assertJsonPath('by_department', null)
            ->assertJsonPath('recent_activity', null);

        // The hidden task never appears anywhere.
        $payload = $response->json();

        $this->assertStringNotContainsString('Colleague private task', json_encode($payload));
        $this->assertStringNotContainsString('Their licence', json_encode($payload));

        $upcomingTitles = array_column($payload['upcoming'], 'title');
        $this->assertContains('My own task', $upcomingTitles);
        $this->assertContains('Shared overdue task', $upcomingTitles);
        $this->assertNotContains('Colleague private task', $upcomingTitles);

        $this->assertSame($ownedItem->id, $this->actingAs($staff, 'sanctum')
            ->getJson('/api/items')
            ->json('data.0.id'));
    }

    public function test_admin_dashboard_reports_org_wide_aggregates(): void
    {
        $admin = $this->user('admin');
        $manager = $this->user('manager');
        $staff = $this->user('staff');
        $client = Client::factory()->create(['name' => 'Acme Ltd']);
        $clientB = Client::factory()->create(['name' => 'Beta Ltd']);
        $department = Department::factory()->create(['name' => 'IT']);
        $today = Carbon::today();

        $task = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->subDays(2)->toDateString(),
            'title' => 'Expired certificate work',
        ]);
        $task->assignees()->sync([$staff->id]);

        $done = Task::factory()->done()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->subDays(5)->toDateString(),
        ]);
        $done->assignees()->sync([$staff->id]);

        Item::factory()->ownedBy($staff)->inDepartments($department)->create([
            'client_id' => $clientB->id,
            'due_date' => $today->copy()->addDays(4)->toDateString(),
            'title' => 'Firewall licence',
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $staff->id,
            'action' => 'created',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'old_values' => null,
            'new_values' => json_encode(['title' => 'Expired certificate work']),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('stats.overdue_tasks', 1) // the done task does not count
            ->assertJsonPath('stats.overdue_items', 0)
            ->assertJsonPath('stats.items_expiring_30', 1)
            ->assertJsonPath('workload.0.user.id', $staff->id)
            ->assertJsonPath('workload.0.open_tasks', 1)
            ->assertJsonPath('workload.0.overdue_tasks', 1)
            ->assertJsonPath('by_client.0.client.name', 'Acme Ltd')
            ->assertJsonPath('by_client.0.open_tasks', 1)
            ->assertJsonPath('by_client.0.active_items', 0)
            ->assertJsonPath('by_department.0.department.name', 'IT')
            ->assertJsonPath('by_department.0.open_tasks', 1)
            ->assertJsonPath('by_department.0.active_items', 1)
            ->assertJsonPath('recent_activity.0.entity_type', 'task')
            ->assertJsonPath('recent_activity.0.entity_id', $task->id)
            ->assertJsonPath('recent_activity.0.entity_title', 'Expired certificate work')
            ->assertJsonPath('recent_activity.0.actor.id', $staff->id)
            ->assertJsonPath('recent_activity.0.actor.name', $staff->name);

        $this->assertNotNull($response->json('items_expiring.within_7'));
        $this->assertNotNull($response->json('workload'));
        $this->assertNotNull($response->json('by_client'));
        $this->assertNotNull($response->json('by_department'));
        $this->assertNotNull($response->json('recent_activity'));

        // Managers see the manager-only panels too.
        $this->actingAs($manager, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('workload.0.user.id', $staff->id);
    }

    public function test_items_expiring_buckets_cover_7_30_and_60_days(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('staff');
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $today = Carbon::today();

        $week = Item::factory()->ownedBy($owner)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->addDays(3)->toDateString(),
            'title' => 'In a week',
        ]);
        $month = Item::factory()->ownedBy($owner)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->addDays(20)->toDateString(),
            'title' => 'In a month',
        ]);
        $twoMonths = Item::factory()->ownedBy($owner)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->addDays(45)->toDateString(),
            'title' => 'In two months',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonCount(1, 'items_expiring.within_7')
            ->assertJsonPath('items_expiring.within_7.0.id', $week->id)
            ->assertJsonCount(1, 'items_expiring.within_30')
            ->assertJsonPath('items_expiring.within_30.0.id', $month->id)
            ->assertJsonCount(1, 'items_expiring.within_60')
            ->assertJsonPath('items_expiring.within_60.0.id', $twoMonths->id);
    }

    public function test_upcoming_mixes_tasks_and_items_and_is_sorted(): void
    {
        $staff = $this->user('staff');
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $today = Carbon::today();

        $task = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => $today->copy()->addDays(3)->toDateString(),
            'title' => 'Task due later',
        ]);
        $task->assignees()->sync([$staff->id]);

        Item::factory()->ownedBy($staff)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $today->copy()->addDay()->toDateString(),
            'title' => 'Item due sooner',
        ]);

        $response = $this->actingAs($staff, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonCount(2, 'upcoming')
            ->assertJsonPath('upcoming.0.type', 'item')
            ->assertJsonPath('upcoming.0.title', 'Item due sooner')
            ->assertJsonPath('upcoming.0.client_name', $client->name)
            ->assertJsonPath('upcoming.1.type', 'task')
            ->assertJsonPath('upcoming.1.id', $task->id);
    }

    public function test_recent_activity_falls_back_to_the_audit_title_and_survives_deletes(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $task = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'title' => 'Soon to be deleted',
        ]);
        $task->delete();

        // No new_values title on the row: the title comes from the soft deleted record.
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'deleted',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('recent_activity.0.entity_title', 'Soon to be deleted')
            ->assertJsonPath('recent_activity.0.actor', null)
            ->assertJsonPath('recent_activity.0.action', 'deleted');

        // A record that no longer exists at all falls back to the audit payload.
        DB::table('audit_logs')->insert([
            'user_id' => $staff->id,
            'action' => 'updated',
            'entity_type' => 'item',
            'entity_id' => 999999,
            'old_values' => null,
            'new_values' => json_encode(['title' => 'From audit values']),
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_activity.0.entity_title', 'From audit values');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
