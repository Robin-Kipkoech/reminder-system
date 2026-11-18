<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProfilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_profile_and_summary_include_grouped_work_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $task = Task::factory()->create([
            'created_by' => $staff->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $task->assignees()->sync([$staff->id]);
        Item::factory()->ownedBy($staff)->inDepartments($department)->create([
            'client_id' => $client->id,
            'created_by' => $staff->id,
        ]);

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();
        $staffRow = collect($list->json('data'))->firstWhere('id', $staff->id);
        $this->assertSame(1, $staffRow['open_tasks_count']);
        $this->assertSame(1, $staffRow['overdue_tasks_count']);
        $this->assertSame(1, $staffRow['items_in_charge_count']);

        $this->getJson('/api/users/summary')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_role.admin', 1)
            ->assertJsonPath('by_role.staff', 1)
            ->assertJsonPath('by_status.active', 2)
            ->assertJsonPath('deleted', 0);

        $this->getJson("/api/users/{$staff->id}/profile")
            ->assertOk()
            ->assertJsonPath('stats.open_tasks', 1)
            ->assertJsonPath('stats.overdue_tasks', 1)
            ->assertJsonPath('stats.items_in_charge', 1)
            ->assertJsonPath('open_tasks.0.id', $task->id);
    }

    public function test_non_admin_cannot_read_another_users_profile(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/users/{$other->id}/profile")
            ->assertForbidden();
    }

    public function test_profile_and_preferences_are_self_service_and_audited(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', ['name' => 'New Name', 'phone' => '555-0101'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '555-0101');

        $this->putJson('/api/auth/preferences', [
            'notify_email' => false,
            'notify_push' => true,
            'digest_time' => '08:30',
        ])->assertOk()
            ->assertJsonPath('notify_email', false)
            ->assertJsonPath('notify_push', true)
            ->assertJsonPath('digest_time', '08:30');

        $this->getJson('/api/auth/preferences')
            ->assertOk()
            ->assertJsonPath('digest_time', '08:30');
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.notify_email', false)
            ->assertJsonPath('data.notify_push', true)
            ->assertJsonPath('data.digest_time', '08:30');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'entity_type' => 'user',
            'action' => 'profile-updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'entity_type' => 'user',
            'action' => 'preferences-updated',
        ]);

        $this->putJson('/api/auth/preferences', [
            'notify_email' => true,
            'notify_push' => false,
            'digest_time' => '25:80',
        ])->assertUnprocessable();
    }

    public function test_notification_type_preferences_default_and_round_trip(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/preferences')
            ->assertOk()
            ->assertJsonPath('types.assigned.email', true)
            ->assertJsonPath('types.assigned.in_app', true)
            ->assertJsonPath('types.comment.email', false)
            ->assertJsonPath('types.comment.in_app', true)
            ->assertJsonPath('types.reminder.email', true)
            ->assertJsonPath('reminder_send_time', '08:00');

        $this->putJson('/api/auth/preferences', [
            'notify_email' => true,
            'notify_push' => false,
            'digest_time' => null,
            'types' => [
                'assigned' => ['email' => false, 'in_app' => true],
                'comment' => ['email' => true],
                'unrecognized' => ['email' => false],
            ],
        ])->assertOk()
            ->assertJsonPath('types.assigned.email', false)
            ->assertJsonPath('types.assigned.in_app', true)
            ->assertJsonPath('types.comment.email', true)
            ->assertJsonPath('types.comment.in_app', true);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'type' => 'assigned',
            'email' => false,
            'in_app' => true,
        ]);
        $this->assertDatabaseMissing('user_notification_preferences', [
            'user_id' => $user->id,
            'type' => 'unrecognized',
        ]);
    }

    public function test_known_type_preferences_require_boolean_values(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/preferences', [
                'notify_email' => true,
                'notify_push' => true,
                'digest_time' => null,
                'types' => ['reminder' => ['email' => 'enabled']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('types.reminder.email');
    }

    public function test_first_time_password_set_does_not_require_the_current_password(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/password', [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'current-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->putJson('/api/auth/password', [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_suspended_login_is_rejected_and_failed_attempt_is_recorded(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
            'email' => 'suspended@example.test',
            'password' => 'valid-password',
        ]);

        $this->withHeader('Origin', 'http://localhost:5174')->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'valid-password',
        ])->assertForbidden()
            ->assertJsonPath('code', 'USER_SUSPENDED');

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => $user->email,
            'successful' => false,
        ]);
        $this->assertDatabaseMissing('login_events', ['email' => $user->email, 'successful' => true]);
    }

    public function test_failed_unknown_login_is_logged_without_password_data(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'never-store-this',
        ])->assertUnprocessable();

        $event = DB::table('login_events')->where('email', 'unknown@example.test')->first();
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->successful);
        $this->assertObjectNotHasProperty('password', $event);
    }

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create([
            'email' => 'success@example.test',
            'password' => 'valid-password',
        ]);

        $this->withHeader('Origin', 'http://localhost:5174')->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'valid-password',
        ])->assertOk();

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => $user->email,
            'successful' => true,
        ]);
    }
}
