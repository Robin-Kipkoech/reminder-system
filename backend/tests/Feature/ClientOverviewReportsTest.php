<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOverviewReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_overview_aggregates_only_work_visible_to_the_user(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $colleague = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $visible = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->subDay()->toDateString(),
            'title' => 'My overdue client task',
        ]);
        Task::factory()->involvedOnly()->create([
            'created_by' => $colleague->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->subDay()->toDateString(),
            'title' => 'Hidden client task',
        ]);
        $visibleItem = Item::factory()->ownedBy($staff)->create([
            'client_id' => $client->id,
            'due_date' => now()->subDay()->toDateString(),
            'title' => 'My active client item',
        ]);
        Item::factory()->ownedBy($colleague)->create([
            'client_id' => $client->id,
            'title' => 'Hidden client item',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/clients/{$client->id}/overview")
            ->assertOk()
            ->assertJsonPath('counts.open_tasks', 1)
            ->assertJsonPath('counts.overdue_tasks', 1)
            ->assertJsonPath('counts.active_items', 1)
            ->assertJsonPath('counts.overdue_items', 1)
            ->assertJsonPath('open_tasks.0.id', $visible->id)
            ->assertJsonPath('items.0.id', $visibleItem->id);

        $this->assertStringNotContainsString('Hidden client task', json_encode($response->json()));
        $this->assertStringNotContainsString('Hidden client item', json_encode($response->json()));
    }

    public function test_reports_require_manager_or_admin_and_validate_dates(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/reports/tasks-by-person')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/tasks-by-client?from=2026-02-31')
            ->assertUnprocessable();
        $this->getJson('/api/reports/items-expiry?from=2026-11-20&to=2026-11-19')
            ->assertUnprocessable();
        $this->getJson('/api/reports/tasks-by-client?format=xml')
            ->assertUnprocessable();

        $this->getJson('/api/reports/tasks-by-person')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_task_reports_calculate_assignee_completion_and_overdue_counts(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $assignee = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create(['name' => 'Report Client']);
        $department = Department::factory()->create();
        $from = now()->subDays(5)->toDateString();
        $to = now()->addDay()->toDateString();

        $onTime = Task::factory()->done()->create([
            'created_by' => $manager->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->toDateString(),
            'completed_at' => now()->subDay(),
            'completed_by' => $assignee->id,
        ]);
        $onTime->assignees()->sync([$assignee->id]);
        $late = Task::factory()->done()->create([
            'created_by' => $manager->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'completed_at' => now()->subDay(),
            'completed_by' => $assignee->id,
        ]);
        $late->assignees()->sync([$assignee->id]);
        $overdue = Task::factory()->create([
            'created_by' => $manager->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $overdue->assignees()->sync([$assignee->id]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/reports/tasks-by-person?from={$from}&to={$to}")
            ->assertOk();
        $person = collect($response->json('data'))->firstWhere('user.id', $assignee->id);
        $this->assertSame(3, $person['assigned']);
        $this->assertSame(2, $person['completed']);
        $this->assertSame(1, $person['completed_on_time']);
        $this->assertSame(1, $person['completed_late']);
        $this->assertSame(1, $person['open_overdue']);

        $clientResponse = $this->getJson("/api/reports/tasks-by-client?from={$from}&to={$to}")
            ->assertOk();
        $clientRow = collect($clientResponse->json('data'))->firstWhere('client.id', $client->id);
        $this->assertSame(1, $clientRow['open']);
        $this->assertSame(1, $clientRow['overdue']);
        $this->assertSame(2, $clientRow['completed']);
    }

    public function test_items_expiry_report_and_csv_are_scoped_and_stream_utf8_bom(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $staff = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create(['name' => 'Renewals Ltd']);
        $department = Department::factory()->create(['name' => 'Operations']);
        $due = now()->addDays(6)->toDateString();
        Item::factory()->ownedBy($staff)->inDepartments($department)->create([
            'client_id' => $client->id,
            'due_date' => $due,
            'title' => 'Certificate expiry report',
        ]);

        $managerResponse = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/reports/items-expiry?from='.now()->toDateString().'&to='.now()->addDays(10)->toDateString())
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Certificate expiry report')
            ->assertJsonPath('data.0.client', 'Renewals Ltd')
            ->assertJsonPath('data.0.departments.0', 'Operations');

        $csv = $this->get('/api/reports/items-expiry?format=csv&from='.now()->toDateString().'&to='.now()->addDays(10)->toDateString())
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent());
        $this->assertStringContainsString('Certificate expiry report', $csv->streamedContent());
        $this->assertStringContainsString('filename=items-expiry.csv', $csv->headers->get('content-disposition'));
        $this->assertSame('Certificate expiry report', $managerResponse->json('data.0.title'));
    }
}
