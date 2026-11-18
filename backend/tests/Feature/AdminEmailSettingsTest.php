<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\EmailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_settings_routes_are_admin_only(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/email-settings')
                ->assertForbidden();
        }
    }

    public function test_get_settings_never_exposes_password_and_uses_environment_without_a_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config([
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.username' => 'mailer-user',
            'mail.mailers.smtp.password' => 'do-not-return-this',
            'mail.from.address' => 'system@example.test',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/email-settings')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('source', 'env')
            ->assertJsonPath('host', 'smtp.example.test')
            ->assertJsonPath('has_password', true)
            ->assertJsonMissingPath('password');
    }

    public function test_update_keeps_a_blank_password_and_audits_only_whether_it_changed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = EmailSetting::query()->create([
            'host' => 'smtp.old.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'old-user',
            'password' => 'secret-password',
            'from_address' => 'old@example.test',
            'from_name' => 'Old sender',
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/email-settings', $this->settingsPayload(['password' => '']))
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('has_password', true)
            ->assertJsonMissingPath('password');

        $this->assertSame('secret-password', $settings->fresh()->password);
        $audit = DB::table('audit_logs')->where('action', 'email-settings-updated')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('secret-password', (string) $audit->old_values);
        $this->assertStringNotContainsString('secret-password', (string) $audit->new_values);
        $this->assertStringNotContainsString('new-secret', (string) $response->getContent());
    }

    public function test_first_settings_row_requires_a_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = $this->settingsPayload();
        unset($payload['password']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/email-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('email_settings', 0);
    }

    public function test_test_endpoint_records_success_and_failure_without_storing_raw_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);
        EmailSetting::query()->create([
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer-user',
            'password' => 'saved-secret',
            'from_address' => 'system@example.test',
            'from_name' => 'Reminder System',
            'is_enabled' => true,
        ]);
        Mail::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/email-settings/test')
            ->assertOk()
            ->assertJsonPath('ok', true);
        Mail::assertSent(NotificationMail::class);
        $this->assertDatabaseHas('email_settings', ['last_test_status' => 'success']);
        $this->assertNotNull(EmailSetting::current()->last_test_at);

        $anotherAdmin = User::factory()->create(['role' => 'admin']);
        Mail::shouldReceive('mailer')
            ->once()
            ->with('smtp')
            ->andThrow(new \RuntimeException('SMTP authentication failed password=topsecret'));

        $this->actingAs($anotherAdmin, 'sanctum')
            ->postJson('/api/admin/email-settings/test')
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Authentication failed. Check the SMTP username and password.');
        $setting = EmailSetting::current();
        $this->assertSame('failed', $setting->last_test_status);
        $this->assertStringNotContainsString('topsecret', $setting->last_test_error);
    }

    public function test_email_log_endpoint_filters_and_paginates_only_email_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $this->insertLog($user, 'email', 'pending');
        $this->insertLog($user, 'in_app', 'sent');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/email-settings/logs?status=pending&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.source_type', 'task');
    }

    private function insertLog(User $user, string $channel, string $status): void
    {
        DB::table('notification_logs')->insert([
            'source_type' => 'task',
            'source_id' => 1,
            'user_id' => $user->id,
            'reminder_period_id' => null,
            'due_date' => now()->toDateString(),
            'channel' => $channel,
            'title' => 'Log entry',
            'message' => 'A sample message.',
            'scheduled_for' => now(),
            'status' => $status,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer-user',
            'password' => 'new-secret',
            'from_address' => 'system@example.test',
            'from_name' => 'Reminder System',
            'is_enabled' => true,
            'reminder_send_time' => '08:00',
            'send_overdue_notices' => true,
        ], $overrides);
    }
}
