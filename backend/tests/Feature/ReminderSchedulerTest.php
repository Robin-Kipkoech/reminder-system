<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Jobs\SendReminderDigestEmail;
use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\ReminderPeriod;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReminderSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_three_day_task_reminder_is_sent_only_once(): void
    {
        $this->setTime('2026-11-18 08:15:00');
        Queue::fake();
        $user = User::factory()->create(['notify_email' => true, 'notify_push' => true]);
        $task = $this->taskFor($user, '2026-11-21', 'open');
        $this->periodFor($task, 3, true);

        Artisan::call('reminders:send');
        Artisan::call('reminders:send');

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('notification_logs', [
            'source_type' => 'task',
            'source_id' => $task->id,
            'user_id' => $user->id,
            'reminder_period_id' => ReminderPeriod::query()->where('offset_days', 3)->value('id'),
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
        Queue::assertPushed(SendNotificationEmail::class, 1);
    }

    public function test_reminders_wait_for_send_time_and_respect_per_type_email_preferences(): void
    {
        $this->setTime('2026-11-18 07:59:00');
        Queue::fake();
        $user = User::factory()->create(['notify_email' => true, 'notify_push' => true]);
        $task = $this->taskFor($user, '2026-11-21', 'open');
        $this->periodFor($task, 3, true);

        Artisan::call('reminders:send');
        $this->assertDatabaseCount('notification_logs', 0);

        Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00', 'Africa/Nairobi'));
        DB::table('user_notification_preferences')->insert([
            'user_id' => $user->id,
            'type' => 'reminder',
            'email' => false,
            'in_app' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Artisan::call('reminders:send');

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $user->id,
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
        Queue::assertNotPushed(SendNotificationEmail::class);
    }

    public function test_digest_user_receives_one_combined_email_for_the_day(): void
    {
        $this->setTime('2026-11-18 08:15:00');
        Queue::fake();
        $user = User::factory()->create([
            'notify_email' => true,
            'notify_push' => true,
            'digest_time' => '08:00:00',
        ]);
        $first = $this->taskFor($user, '2026-11-18', 'open', 'First task');
        $second = $this->taskFor($user, '2026-11-19', 'in_progress', 'Second task');
        $this->periodFor($first, 0, true);
        $this->periodFor($second, 1, true);

        Artisan::call('reminders:send');

        Queue::assertPushed(SendReminderDigestEmail::class, 1);
        Queue::assertPushed(SendReminderDigestEmail::class, fn (SendReminderDigestEmail $job): bool => count($job->entries) === 2);
        Queue::assertNotPushed(SendNotificationEmail::class);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertSame(2, DB::table('notification_logs')->where('channel', 'email')->where('user_id', $user->id)->count());
    }

    public function test_overdue_notices_are_deduplicated_per_day_and_skip_inactive_entries(): void
    {
        $this->setTime('2026-11-18 08:15:00');
        Queue::fake();
        $user = User::factory()->create(['notify_email' => true]);
        $client = Client::factory()->create();
        $actor = User::factory()->create();
        $activeItem = Item::factory()->create([
            'client_id' => $client->id,
            'created_by' => $actor->id,
            'due_date' => '2026-11-17',
            'status' => 'active',
        ]);
        $activeItem->owners()->sync([$user->id]);
        $cancelled = Item::factory()->create([
            'client_id' => $client->id,
            'created_by' => $actor->id,
            'due_date' => '2026-11-17',
            'status' => 'cancelled',
        ]);
        $cancelled->owners()->sync([$user->id]);

        Artisan::call('reminders:send');
        Artisan::call('reminders:send');

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('notification_logs', [
            'source_type' => 'item',
            'source_id' => $activeItem->id,
            'user_id' => $user->id,
            'due_date' => '2026-11-18',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_logs', [
            'source_type' => 'item',
            'source_id' => $cancelled->id,
            'channel' => 'in_app',
        ]);
        Queue::assertPushed(SendNotificationEmail::class, 1);
    }

    public function test_done_tasks_cancelled_items_and_inactive_periods_are_not_candidates(): void
    {
        $this->setTime('2026-11-18 08:15:00');
        Queue::fake();
        $user = User::factory()->create();
        $doneTask = $this->taskFor($user, '2026-11-21', 'done');
        $this->periodFor($doneTask, 3, true);

        $client = Client::factory()->create();
        $creator = User::factory()->create();
        $cancelled = Item::factory()->create([
            'client_id' => $client->id,
            'created_by' => $creator->id,
            'due_date' => '2026-11-21',
            'status' => 'cancelled',
        ]);
        $cancelled->owners()->sync([$user->id]);
        $this->periodFor($cancelled, 3, true);

        $inactive = Item::factory()->create([
            'client_id' => $client->id,
            'created_by' => $creator->id,
            'due_date' => '2026-11-23',
            'status' => 'active',
        ]);
        $inactive->owners()->sync([$user->id]);
        $this->periodFor($inactive, 5, false);

        Artisan::call('reminders:send');

        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNotPushed(SendNotificationEmail::class);
    }

    private function taskFor(User $user, string $dueDate, string $status, string $title = 'Reminder task'): Task
    {
        $task = Task::factory()->create([
            'created_by' => User::factory()->create()->id,
            'client_id' => Client::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'due_date' => $dueDate,
            'status' => $status,
            'title' => $title,
        ]);
        $task->assignees()->sync([$user->id]);

        return $task;
    }

    private function periodFor(Task|Item $entry, int $offsetDays, bool $active): void
    {
        $period = ReminderPeriod::query()->firstOrCreate(
            ['offset_days' => $offsetDays],
            ['label' => "{$offsetDays} days", 'is_active' => $active],
        );
        $period->forceFill(['is_active' => $active])->save();
        $entry->reminderPeriods()->syncWithoutDetaching([$period->id]);
    }

    private function setTime(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime, 'Africa/Nairobi'));
    }
}
