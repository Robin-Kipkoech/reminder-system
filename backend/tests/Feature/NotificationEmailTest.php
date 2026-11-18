<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Mail\NotificationMail;
use App\Models\Client;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function taskPayload(Client $client, Department $department, array $assignees): array
    {
        return [
            'title' => 'Task for email notifications',
            'description' => '',
            'client_id' => $client->id,
            'department_id' => $department->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'visibility' => 'everyone',
            'assignee_ids' => $assignees,
            'reminder_period_ids' => [],
            'is_recurring' => false,
        ];
    }

    public function test_assigning_a_task_queues_an_email_to_new_assignee_only(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $assignee = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true, 'email' => 'assignee@example.test']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/tasks', $this->taskPayload($client, $department, [$assignee->id]))
            ->assertCreated();

        $taskId = $response->json('data.id');

        Queue::assertPushed(SendNotificationEmail::class, function (SendNotificationEmail $job) use ($assignee): bool {
            return $job->userId === $assignee->id
                && str_contains((string) $job->actionUrl, '/tasks?open=');
        });
        Queue::assertPushed(SendNotificationEmail::class, 1);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $assignee->id,
            'source_type' => 'task',
            'source_id' => $taskId,
            'channel' => 'email',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('notification_logs', ['user_id' => $actor->id, 'channel' => 'email']);
    }

    public function test_assigner_does_not_get_email_for_their_own_action(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/tasks', $this->taskPayload($client, $department, [$actor->id, $other->id]))
            ->assertCreated();

        Queue::assertPushed(SendNotificationEmail::class, 1);
        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->userId === $other->id);
        Queue::assertNotPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->userId === $actor->id);
    }

    public function test_user_with_notify_email_false_gets_no_email_but_still_gets_in_app_notification(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => false, 'email' => 'noemail@example.test']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/tasks', $this->taskPayload($client, $department, [$assignee->id]))
            ->assertCreated();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $assignee->id,
            'type' => 'assigned',
            'source_type' => 'task',
        ]);
        Queue::assertNotPushed(SendNotificationEmail::class);
        $this->assertDatabaseMissing('notification_logs', ['user_id' => $assignee->id, 'channel' => 'email']);
    }

    public function test_suspended_user_gets_no_email(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $creator = User::factory()->create(['role' => 'staff', 'status' => 'suspended', 'notify_email' => true, 'email' => 'suspended@example.test']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status' => 'open',
        ]);
        $task->assignees()->sync([$actor->id]);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk();

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'completed',
            'source_id' => $task->id,
        ]);
        Queue::assertNotPushed(SendNotificationEmail::class);
        $this->assertDatabaseMissing('notification_logs', ['user_id' => $creator->id, 'channel' => 'email']);
    }

    public function test_comment_notification_sends_no_email_but_mention_sends_one(): void
    {
        Queue::fake();
        $creator = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $mentioned = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true, 'name' => 'Mention Target']);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'visibility' => 'everyone',
        ]);
        $task->assignees()->sync([$actor->id, $mentioned->id]);

        $this->actingAs($actor, 'sanctum')->postJson("/api/tasks/{$task->id}/comments", [
            'body' => "Please review @[{$mentioned->name}](user:{$mentioned->id})",
        ])->assertCreated();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $creator->id, 'type' => 'comment']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $mentioned->id, 'type' => 'mention']);

        Queue::assertPushed(SendNotificationEmail::class, 1);
        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->userId === $mentioned->id);
        Queue::assertNotPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->userId === $creator->id);
    }

    public function test_comment_email_is_sent_only_after_the_recipient_enables_it(): void
    {
        Queue::fake();
        $creator = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $actor = User::factory()->create(['role' => 'staff', 'status' => 'active', 'notify_email' => true]);
        $client = Client::factory()->create();
        $department = Department::factory()->create();
        $task = Task::factory()->create([
            'created_by' => $creator->id,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'visibility' => 'everyone',
        ]);
        $task->assignees()->sync([$actor->id]);
        DB::table('user_notification_preferences')->insert([
            'user_id' => $creator->id,
            'type' => 'comment',
            'email' => true,
            'in_app' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor, 'sanctum')->postJson("/api/tasks/{$task->id}/comments", [
            'body' => 'A comment that the creator opted to receive.',
        ])->assertCreated();

        Queue::assertPushed(SendNotificationEmail::class, 1);
        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job): bool => $job->userId === $creator->id);
    }

    public function test_mail_redirect_to_redirects_outside_production(): void
    {
        Mail::fake();
        config([
            'app.env' => 'local',
            'app.frontend_url' => 'http://localhost:5173',
            'mail.redirect_to' => 'safe@example.test',
        ]);

        $recipient = User::factory()->create([
            'name' => 'Alice Example',
            'email' => 'alice@example.test',
            'status' => 'active',
            'notify_email' => true,
        ]);

        $logId = (int) DB::table('notification_logs')->insertGetId([
            'source_type' => 'task',
            'source_id' => 1,
            'user_id' => $recipient->id,
            'reminder_period_id' => null,
            'due_date' => now()->toDateString(),
            'channel' => 'email',
            'title' => 'Task assigned',
            'message' => 'You were assigned a task.',
            'scheduled_for' => now(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new SendNotificationEmail(
            $logId,
            $recipient->id,
            'Task assigned',
            'You were assigned a task.',
            'http://localhost:5173/tasks?open=1',
        );

        $job->handle();

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return $mail->hasTo('safe@example.test');
        });
        $this->assertStringContainsString('[to: alice@example.test]', Mail::sent(NotificationMail::class)->first()->subject);

        $this->assertDatabaseHas('notification_logs', [
            'id' => $logId,
            'status' => 'sent',
        ]);
    }

    public function test_failed_send_marks_log_as_failed_and_does_not_break_request(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $recipient = User::factory()->create([
            'email' => 'alice@example.test',
            'status' => 'active',
            'notify_email' => true,
        ]);

        $logId = (int) DB::table('notification_logs')->insertGetId([
            'source_type' => 'task',
            'source_id' => 1,
            'user_id' => $recipient->id,
            'reminder_period_id' => null,
            'due_date' => now()->toDateString(),
            'channel' => 'email',
            'title' => 'Task assigned',
            'message' => 'You were assigned a task.',
            'scheduled_for' => now(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new SendNotificationEmail(
            $logId,
            $recipient->id,
            'Task assigned',
            'You were assigned a task.',
            'http://localhost:5173/tasks?open=1',
        );

        try {
            $job->handle();
        } catch (\RuntimeException $e) {
            // expected; job rethrows so queue can retry/fail
        }
        $job->failed(new \RuntimeException('SMTP unavailable'));

        $this->assertDatabaseHas('notification_logs', [
            'id' => $logId,
            'status' => 'failed',
        ]);
        $this->assertNotNull(DB::table('notification_logs')->where('id', $logId)->value('error_message'));
    }
}
