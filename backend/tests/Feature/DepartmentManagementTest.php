<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeTask(int $clientId, int $departmentId, int $createdBy, string $status = 'open'): int
    {
        return DB::table('tasks')->insertGetId([
            'title' => 'Sample task',
            'department_id' => $departmentId,
            'client_id' => $clientId,
            'created_by' => $createdBy,
            'due_date' => now()->toDateString(),
            'visibility' => 'everyone',
            'status' => $status,
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeItem(int $clientId, int $createdBy, string $status = 'active'): int
    {
        return DB::table('items')->insertGetId([
            'title' => 'Sample item',
            'client_id' => $clientId,
            'created_by' => $createdBy,
            'due_date' => now()->toDateString(),
            'status' => $status,
            'is_recurring' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_crud_departments_and_every_action_is_audited(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/departments', [
            'name' => 'Finance',
            'description' => 'Handles invoicing',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Finance')
            ->assertJsonPath('data.description', 'Handles invoicing')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->getJson('/api/departments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $id, 'name' => 'Finance']);

        $this->getJson("/api/departments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Handles invoicing');

        $this->putJson("/api/departments/{$id}", ['name' => 'Finance & Admin'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Finance & Admin');

        $this->postJson("/api/departments/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/departments/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        $this->deleteJson("/api/departments/{$id}")->assertOk();
        $this->assertSoftDeleted('departments', ['id' => $id]);

        $this->postJson("/api/departments/{$id}/restore")->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNotSoftDeleted('departments', ['id' => $id]);

        foreach (['created', 'updated', 'activated', 'deactivated', 'deleted', 'restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $admin->id,
                'entity_type' => 'department',
                'entity_id' => $id,
                'action' => $action,
            ]);
        }
    }

    public function test_managers_and_staff_can_read_but_cannot_write_departments(): void
    {
        $department = Department::factory()->create(['name' => 'Support']);

        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');

            $this->getJson('/api/departments')->assertOk()->assertJsonFragment(['name' => 'Support']);
            $this->getJson('/api/departments/options')
                ->assertOk()
                ->assertJsonFragment(['id' => $department->id, 'name' => 'Support'])
                ->assertJsonMissingPath('data.0.description');
            $this->getJson("/api/departments/{$department->id}")->assertOk();

            $this->postJson('/api/departments', ['name' => 'Blocked'])->assertForbidden();
            $this->putJson("/api/departments/{$department->id}", ['name' => 'Blocked'])->assertForbidden();
            $this->postJson("/api/departments/{$department->id}/activate")->assertForbidden();
            $this->postJson("/api/departments/{$department->id}/deactivate")->assertForbidden();
            $this->postJson("/api/departments/{$department->id}/restore")->assertForbidden();
            $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Support']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_department_names_are_rejected(): void
    {
        Department::factory()->create(['name' => 'Development']);

        $this->actingAs($this->user('admin'), 'sanctum');

        $this->postJson('/api/departments', ['name' => 'Development'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('departments', 1);
    }

    public function test_deactivate_and_delete_report_open_tasks_and_active_items(): void
    {
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $this->makeTask($client->id, $department->id, $admin->id, 'in_progress');
        $this->makeTask($client->id, $department->id, $admin->id, 'done');

        $activeItem = $this->makeItem($client->id, $admin->id, 'active');
        $expiredItem = $this->makeItem($client->id, $admin->id, 'expired');

        foreach ([$activeItem, $expiredItem] as $itemId) {
            DB::table('item_departments')->insert([
                'item_id' => $itemId,
                'department_id' => $department->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/departments/{$department->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('meta.open_tasks', 1)
            ->assertJsonPath('meta.active_items', 1);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('meta.open_tasks', 1)
            ->assertJsonPath('meta.active_items', 1);
    }

    public function test_deactivated_departments_are_missing_from_options(): void
    {
        $active = Department::factory()->create(['name' => 'Alpha Dept']);
        Department::factory()->inactive()->create(['name' => 'Beta Dept']);
        $trashed = Department::factory()->create(['name' => 'Gamma Dept']);
        $trashed->delete();

        $this->actingAs($this->user('manager'), 'sanctum');

        $this->getJson('/api/departments/options')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $active->id, 'name' => 'Alpha Dept']);

        $this->getJson('/api/departments?with_trashed=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Gamma Dept']);
    }
}
