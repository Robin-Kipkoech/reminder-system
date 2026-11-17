<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff', string $status = 'active', array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
            ...$attributes,
        ]);
    }

    public function test_admin_can_create_list_show_and_update_users(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/users', [
            'name' => 'Taylor Reed',
            'email' => 'taylor@example.test',
            'role' => 'staff',
            'phone' => '555-0142',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Taylor Reed')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.phone', '555-0142')
            ->assertJsonStructure(['temporary_password'])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $id = $created->json('data.id');
        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['email' => 'taylor@example.test']);
        $this->getJson("/api/users/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson("/api/users/{$id}", [
            'name' => 'Taylor Updated',
            'email' => 'taylor@example.test',
            'role' => 'manager',
            'status' => 'active',
            'phone' => null,
        ])->assertOk()->assertJsonPath('data.name', 'Taylor Updated');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'entity_type' => 'user',
            'entity_id' => $id,
            'action' => 'created',
        ]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'updated']);

        $audit = DB::table('audit_logs')->where('entity_id', $id)->get();
        foreach ($audit as $entry) {
            foreach (['old_values', 'new_values'] as $field) {
                if ($entry->{$field} !== null) {
                    $values = json_decode($entry->{$field}, true);
                    $this->assertArrayNotHasKey('password', $values);
                    $this->assertArrayNotHasKey('remember_token', $values);
                }
            }
        }
    }

    public function test_generated_password_is_not_returned_when_admin_supplies_one(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Manual Password',
                'email' => 'manual@example.test',
                'role' => 'staff',
                'password' => 'a-strong-password',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_login_and_me_responses_include_password_change_flag(): void
    {
        $user = $this->user('staff', 'active', [
            'email' => 'force-change@example.test',
            'password' => 'temporary-pass',
            'must_change_password' => true,
        ]);

        $this->withHeader('Origin', 'http://localhost:5174')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'temporary-pass',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_managers_and_staff_are_forbidden_from_admin_routes_but_can_use_options(): void
    {
        $activeStaff = $this->user('staff');
        $this->user('staff', 'suspended');
        $this->user('manager', 'active', ['name' => 'Morgan Manager']);

        foreach (['staff', 'manager'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');
            $this->getJson('/api/users')->assertForbidden();
            $this->getJson('/api/users/options')
                ->assertOk()
                ->assertJsonFragment(['id' => $activeStaff->id, 'name' => $activeStaff->name, 'role' => 'staff'])
                ->assertJsonMissingPath('data.0.email')
                ->assertJsonMissingPath('data.0.phone')
                ->assertJsonMissingPath('data.0.status');
        }
    }

    public function test_admin_cannot_suspend_or_delete_their_own_account(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/users/{$admin->id}/suspend")->assertForbidden();
        $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();
        $this->putJson("/api/users/{$admin->id}", ['role' => 'staff'])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $this->assertNull($admin->fresh()->deleted_at);
    }

    public function test_last_active_admin_cannot_be_suspended_deleted_or_demoted(): void
    {
        $admin = $this->user('admin');
        $manager = $this->user('manager');
        $service = app(UserService::class);

        try {
            $service->suspend($manager, $admin, '127.0.0.1');
            $this->fail('The last active admin should not be suspendable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        try {
            $service->delete($manager, $admin, '127.0.0.1');
            $this->fail('The last active admin should not be deletable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        try {
            $service->update($manager, $admin, ['role' => 'manager'], '127.0.0.1');
            $this->fail('The last active admin should not be demotable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'active', 'role' => 'admin']);
    }

    public function test_suspended_user_is_denied_on_their_next_request_and_access_is_revoked(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->user('admin');
        $target = $this->user('staff');
        $token = $target->createToken('test-device');
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/users/{$target->id}/suspend")
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->actingAs($target->fresh(), 'sanctum')
            ->getJson('/api/users/options')
            ->assertForbidden()
            ->assertJsonPath('code', 'USER_SUSPENDED');
    }

    public function test_duplicate_email_is_rejected_when_existing_user_is_soft_deleted(): void
    {
        $deleted = $this->user('staff', 'active', ['email' => 'reused@example.test']);
        $deleted->delete();

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Duplicate Email',
                'email' => 'reused@example.test',
                'role' => 'staff',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_password_change_gate_allows_only_me_password_and_logout_routes(): void
    {
        $user = $this->user('staff', 'active', ['must_change_password' => true]);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/users/options')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->getJson('/api/users/options')->assertOk();
    }

    public function test_user_actions_are_audited_and_delete_can_be_restored(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff');
        $this->actingAs($admin, 'sanctum');

        $this->postJson("/api/users/{$target->id}/reset-password")->assertOk()->assertJsonStructure(['temporary_password']);
        $this->postJson("/api/users/{$target->id}/suspend")->assertOk();
        $this->postJson("/api/users/{$target->id}/activate")->assertOk();
        $this->deleteJson("/api/users/{$target->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->postJson("/api/users/{$target->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('users', ['id' => $target->id]);

        foreach (['reset-password', 'suspended', 'activated', 'deleted', 'restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $admin->id,
                'entity_id' => $target->id,
                'action' => $action,
            ]);
        }
    }
}
