<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderDigestEmail;
use App\Models\EmailSetting;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send due-date reminders and daily overdue notices';

    public function handle(NotificationService $notifications): int
    {
        $now = Carbon::now('Africa/Nairobi');
        $today = $now->toDateString();
        $settings = EmailSetting::current();
        $sendOverdue = $settings?->send_overdue_notices ?? true;
        $organisationTime = substr((string) ($settings?->reminder_send_time ?? '08:00:00'), 0, 5);
        $candidates = $this->reminderCandidates($today);
        if ($sendOverdue) {
            $candidates = [...$candidates, ...$this->overdueCandidates($today)];
        }

        $candidateCount = 0;
        $sentCount = 0;
        $skippedCount = 0;
        $digests = [];

        foreach ($candidates as $candidate) {
            /** @var Task|Item $source */
            $source = $candidate['source'];
            $recipients = $candidate['recipients'];
            foreach ($recipients as $user) {
                $candidateCount++;
                $effectiveTime = $user->digest_time === null
                    ? $organisationTime
                    : substr((string) $user->digest_time, 0, 5);
                if ($now->format('H:i') < $effectiveTime) {
                    $skippedCount++;

                    continue;
                }

                $type = $source instanceof Task ? 'task' : 'item';
                $sourceId = (int) $source->getKey();
                $periodId = $candidate['reminder_period_id'];
                $logDate = $candidate['log_date'];
                $title = $candidate['overdue'] ? 'Overdue '.ucfirst($type) : 'Reminder: '.ucfirst($type).' due soon';
                $message = $candidate['overdue']
                    ? "This {$type} is overdue: {$source->title}"
                    : "This {$type} is coming due: {$source->title}";
                $channels = $notifications->channelsFor($user, 'reminder');

                try {
                    $processed = DB::transaction(function () use (
                        $channels, $logDate, $message, $notifications, $periodId,
                        $source, $sourceId, $title, $type, $user, $today
                    ): array|false {
                        $alreadyProcessed = DB::table('notification_logs')
                            ->where('source_type', $type)
                            ->where('source_id', $sourceId)
                            ->where('user_id', $user->getKey())
                            ->where('reminder_period_id', $periodId)
                            ->where('due_date', $logDate)
                            ->where('channel', 'in_app')
                            ->exists();
                        if ($alreadyProcessed) {
                            return false;
                        }

                        $hasChannel = $channels['in_app'] || $channels['email'];
                        $now = Carbon::now('Africa/Nairobi');
                        DB::table('notification_logs')->insert([
                            'source_type' => $type,
                            'source_id' => $sourceId,
                            'user_id' => $user->getKey(),
                            'reminder_period_id' => $periodId,
                            'due_date' => $logDate,
                            'channel' => 'in_app',
                            'title' => $title,
                            'message' => $message,
                            'scheduled_for' => $now,
                            'sent_at' => $hasChannel ? $now : null,
                            'status' => $hasChannel ? 'sent' : 'skipped',
                            'error_message' => null,
                            'read_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        if (! $hasChannel) {
                            return ['has_channel' => false];
                        }

                        $notifications->notify(
                            $user,
                            'reminder',
                            $title,
                            $message,
                            $source,
                            [$type.'_id' => $sourceId],
                            null,
                            $periodId,
                            $logDate,
                            $user->digest_time === null,
                        );

                        if ($user->digest_time !== null && $channels['email']) {
                            $logId = $this->createEmailLog(
                                (int) $user->getKey(),
                                $type,
                                $sourceId,
                                $periodId,
                                $logDate,
                                $title,
                                $message,
                            );

                            return [
                                'has_channel' => true,
                                'digest_log_id' => $logId,
                                'digest_entry' => $this->digestEntry($source, $type, $today),
                            ];
                        }

                        return ['has_channel' => true];
                    });
                } catch (QueryException $exception) {
                    if ($this->isDuplicateKey($exception)) {
                        $skippedCount++;

                        continue;
                    }
                    throw $exception;
                }

                if ($processed === false) {
                    $skippedCount++;

                    continue;
                }

                if (! $processed['has_channel']) {
                    $skippedCount++;

                    continue;
                }

                $sentCount++;
                if (isset($processed['digest_log_id'])) {
                    $userId = (int) $user->getKey();
                    $digests[$userId]['log_ids'][] = $processed['digest_log_id'];
                    $digests[$userId]['entries'][] = $processed['digest_entry'];
                }
            }
        }

        foreach ($digests as $userId => $digest) {
            DB::afterCommit(fn () => SendReminderDigestEmail::dispatch(
                (int) $userId,
                $digest['log_ids'],
                $digest['entries'],
            ));
        }

        $this->line("candidates: {$candidateCount}, sent: {$sentCount}, skipped: {$skippedCount}");

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{source:Task|Item,recipients:iterable<User>,reminder_period_id:?int,log_date:string,overdue:bool}>
     */
    private function reminderCandidates(string $today): array
    {
        $candidates = [];
        $tasks = Task::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->with(['client', 'assignees' => fn ($query) => $query->where('users.status', 'active'), 'reminderPeriods' => fn ($query) => $query->where('is_active', true)])
            ->whereHas('reminderPeriods', fn ($query) => $query->where('is_active', true))
            ->get();

        foreach ($tasks as $task) {
            foreach ($task->reminderPeriods as $period) {
                if (Carbon::parse($task->due_date)->subDays($period->offset_days)->toDateString() === $today) {
                    $candidates[] = [
                        'source' => $task,
                        'recipients' => $task->assignees,
                        'reminder_period_id' => (int) $period->getKey(),
                        'log_date' => Carbon::parse($task->due_date)->toDateString(),
                        'overdue' => false,
                    ];
                }
            }
        }

        $items = Item::query()
            ->where('status', 'active')
            ->with(['client', 'owners' => fn ($query) => $query->where('users.status', 'active'), 'reminderPeriods' => fn ($query) => $query->where('is_active', true)])
            ->whereHas('reminderPeriods', fn ($query) => $query->where('is_active', true))
            ->get();
        foreach ($items as $item) {
            foreach ($item->reminderPeriods as $period) {
                if (Carbon::parse($item->due_date)->subDays($period->offset_days)->toDateString() === $today) {
                    $candidates[] = [
                        'source' => $item,
                        'recipients' => $item->owners,
                        'reminder_period_id' => (int) $period->getKey(),
                        'log_date' => Carbon::parse($item->due_date)->toDateString(),
                        'overdue' => false,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int,array{source:Task|Item,recipients:iterable<User>,reminder_period_id:null,log_date:string,overdue:bool}>
     */
    private function overdueCandidates(string $today): array
    {
        $candidates = [];
        $tasks = Task::query()->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<', $today)
            ->with(['client', 'assignees' => fn ($query) => $query->where('users.status', 'active')])
            ->get();
        foreach ($tasks as $task) {
            $candidates[] = [
                'source' => $task,
                'recipients' => $task->assignees,
                'reminder_period_id' => null,
                'log_date' => $today,
                'overdue' => true,
            ];
        }

        $items = Item::query()->where('status', 'active')
            ->whereDate('due_date', '<', $today)
            ->with(['client', 'owners' => fn ($query) => $query->where('users.status', 'active')])
            ->get();
        foreach ($items as $item) {
            $candidates[] = [
                'source' => $item,
                'recipients' => $item->owners,
                'reminder_period_id' => null,
                'log_date' => $today,
                'overdue' => true,
            ];
        }

        return $candidates;
    }

    private function createEmailLog(
        int $userId,
        string $type,
        int $sourceId,
        ?int $periodId,
        string $dueDate,
        string $title,
        string $message,
    ): int {
        $now = Carbon::now('Africa/Nairobi');

        return (int) DB::table('notification_logs')->insertGetId([
            'source_type' => $type,
            'source_id' => $sourceId,
            'user_id' => $userId,
            'reminder_period_id' => $periodId,
            'due_date' => $dueDate,
            'channel' => 'email',
            'title' => $title,
            'message' => $message,
            'scheduled_for' => $now,
            'status' => 'pending',
            'error_message' => null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{source_type:string,title:string,client:string,due_date:string,days_remaining:int,url:string}
     */
    private function digestEntry(Task|Item $source, string $type, string $today): array
    {
        $dueDate = Carbon::parse($source->due_date)->toDateString();

        return [
            'source_type' => $type,
            'title' => (string) $source->title,
            'client' => (string) ($source->client?->name ?? ''),
            'due_date' => $dueDate,
            'days_remaining' => (int) Carbon::parse($today)->diffInDays(Carbon::parse($dueDate), false),
            'url' => rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
                .($source instanceof Task ? '/tasks?open=' : '/items?open=').$source->getKey(),
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique')
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}
