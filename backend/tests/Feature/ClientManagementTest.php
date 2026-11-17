<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientManagementTest extends TestCase
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

    public function test_admin_can_create_list_show_update_activate_deactivate_delete_and_restore_clients(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/clients', [
            'name' => 'Acme Holdings',
            'contact_person' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'phone' => '+254700111222',
            'address' => 'Kimathi Street, Nairobi',
            'notes' => 'Key account',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Holdings')
            ->assertJsonPath('data.contact_person', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@acme.test')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $id, 'name' => 'Acme Holdings']);

        $this->getJson("/api/clients/{$id}")
            ->assertOk()
            ->assertJsonPath('data.notes', 'Key account');

        $this->putJson("/api/clients/{$id}", ['name' => 'Acme Holdings Ltd', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Holdings Ltd')
            ->assertJsonPath('data.is_active', false);

        $this->postJson("/api/clients/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('message', 'Client activated.');

        $this->postJson("/api/clients/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', 'Client deactivated.');

        $this->deleteJson("/api/clients/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Client deleted.');
        $this->assertSoftDeleted('clients', ['id' => $id]);

        $this->postJson("/api/clients/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Acme Holdings Ltd');
        $this->assertNotSoftDeleted('clients', ['id' => $id]);

        foreach (['created', 'updated', 'activated', 'deactivated', 'deleted', 'restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $admin->id,
                'entity_type' => 'client',
                'entity_id' => $id,
                'action' => $action,
            ]);
        }

        $audit = DB::table('audit_logs')->where('entity_type', 'client')->where('action', 'created')->first();
        $this->assertNotNull($audit->new_values);
        $this->assertNull($audit->old_values);
        $this->assertSame('Acme Holdings', json_decode($audit->new_values, true)['name']);
    }

    public function test_managers_and_staff_can_read_but_cannot_write_clients(): void
    {
        $client = Client::factory()->create(['name' => 'Readable Client']);

        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');

            $this->getJson('/api/clients')
                ->assertOk()
                ->assertJsonFragment(['name' => 'Readable Client']);

            $this->getJson('/api/clients/options')
                ->assertOk()
                ->assertJsonFragment(['id' => $client->id, 'name' => 'Readable Client'])
                ->assertJsonMissingPath('data.0.email');

            $this->getJson("/api/clients/{$client->id}")->assertOk();

            $this->postJson('/api/clients', ['name' => 'Blocked Client'])
                ->assertForbidden()
                ->assertJsonPath('code', 'FORBIDDEN');

            $this->putJson("/api/clients/{$client->id}", ['name' => 'Blocked Client'])->assertForbidden();
            $this->postJson("/api/clients/{$client->id}/activate")->assertForbidden();
            $this->postJson("/api/clients/{$client->id}/deactivate")->assertForbidden();
            $this->postJson("/api/clients/{$client->id}/restore")->assertForbidden();
            $this->deleteJson("/api/clients/{$client->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Readable Client', 'is_active' => true]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_with_trashed_is_limited_to_admins(): void
    {
        Client::factory()->create(['name' => 'Live Client']);
        $deleted = Client::factory()->create(['name' => 'Deleted Client']);
        $deleted->delete();

        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');

            $this->getJson('/api/clients?with_trashed=1')
                ->assertOk()
                ->assertJsonFragment(['name' => 'Live Client'])
                ->assertJsonMissing(['name' => 'Deleted Client'])
                ->assertJsonCount(1, 'data');
        }

        $this->actingAs($this->user('admin'), 'sanctum');

        $this->getJson('/api/clients?with_trashed=true')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Deleted Client']);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['name' => 'Deleted Client']);
    }

    public function test_duplicate_client_names_are_rejected(): void
    {
        Client::factory()->create(['name' => 'Acme Holdings']);

        $deleted = Client::factory()->create(['name' => 'Retired Client']);
        $deleted->delete();

        $this->actingAs($this->user('admin'), 'sanctum');

        $this->postJson('/api/clients', ['name' => 'Acme Holdings'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // The unique index still covers soft-deleted rows, so the name is unusable.
        $this->postJson('/api/clients', ['name' => 'Retired Client'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('clients', 2);
    }

    public function test_validation_rejects_missing_name_and_invalid_email(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->user('admin'), 'sanctum');

        $this->postJson('/api/clients', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/clients', ['name' => 'Bad Email', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->putJson("/api/clients/{$client->id}", ['is_active' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->getJson('/api/clients/999999')->assertNotFound();
    }

    public function test_deactivate_and_delete_report_open_tasks_and_active_items(): void
    {
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $this->makeTask($client->id, $department->id, $admin->id, 'open');
        $this->makeTask($client->id, $department->id, $admin->id, 'done');
        $this->makeItem($client->id, $admin->id, 'active');
        $this->makeItem($client->id, $admin->id, 'expired');

        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/clients/{$client->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('meta.open_tasks', 1)
            ->assertJsonPath('meta.active_items', 1);

        $this->postJson("/api/clients/{$client->id}/activate")->assertOk();

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('meta.open_tasks', 1)
            ->assertJsonPath('meta.active_items', 1);
    }

    public function test_options_hide_deactivated_clients_and_support_filters_and_sorting(): void
    {
        $active = Client::factory()->create(['name' => 'Alpha Active']);
        Client::factory()->inactive()->create(['name' => 'Beta Inactive']);
        $trashed = Client::factory()->create(['name' => 'Gamma Trashed']);
        $trashed->delete();

        $this->actingAs($this->user('staff'), 'sanctum');

        $this->getJson('/api/clients/options')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $active->id, 'name' => 'Alpha Active'])
            ->assertJsonMissingPath('data.0.email');

        $this->getJson('/api/clients?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Beta Inactive']);

        $this->getJson('/api/clients?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/clients?sort=name&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beta Inactive');

        $this->getJson('/api/clients?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
