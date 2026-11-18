<?php

namespace App\Services;

use App\Jobs\SendNotificationEmail;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    private const EMAIL_TYPES = ['assigned', 'mention', 'comment', 'completed', 'reminder'];

    /**
     * @param  User|iterable<User>  $users
     * @param  array<string, mixed>|null  $data
     */
    public function notify(
        User|iterable $users,
        string $type,
        string $title,
        string $message,
        Task|Item|null $source = null,
        ?array $data = null,
        ?User $actor = null,
        ?int $reminderPeriodId = null,
        ?string $logDueDate = null,
        bool $sendEmail = true,
    ): void {
        $actorId = $actor?->getKey() ?? auth()->user()?->getAuthIdentifier();
        $recipients = $users instanceof User ? [$users] : $users;
        $usersById = collect($recipients)
            ->filter(fn ($user): bool => $user instanceof User)
            ->keyBy(fn (User $user): int => (int) $user->getKey());
        $ids = $usersById->keys()
            ->filter(fn (int $id): bool => $id > 0 && $id !== (int) $actorId)
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $activeUsers = User::query()->whereIn('id', $ids)->where('status', 'active')->get()->keyBy('id');
        $dispatches = [];
        $encodedData = $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR);
        $sourceType = $source instanceof Task ? 'task' : ($source instanceof Item ? 'item' : null);
        $sourceId = $source?->getKey();

        foreach ($activeUsers as $recipient) {
            $channels = $this->channelsFor($recipient, $type);
            $allowInApp = $channels['in_app'];
            $allowEmail = $sendEmail && $channels['email'];

            if ($allowInApp) {
                DB::table('user_notifications')->insert([
                    'user_id' => $recipient->getKey(),
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'data' => $encodedData,
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($allowEmail && $sourceType !== null && $sourceId !== null) {
                $dueDate = $logDueDate ?: $this->dueDateForLog($source);
                $logId = $this->createPendingEmailLog(
                    (int) $recipient->getKey(),
                    $sourceType,
                    (int) $sourceId,
                    $reminderPeriodId,
                    $dueDate,
                    $title,
                    $message,
                );
                $dispatches[] = [
                    'logId' => $logId,
                    'userId' => (int) $recipient->getKey(),
                    'title' => $title,
                    'message' => $message,
                    'actionUrl' => $this->actionUrl($source),
                ];
            }
        }

        if ($dispatches !== []) {
            DB::afterCommit(function () use ($dispatches): void {
                foreach ($dispatches as $dispatch) {
                    SendNotificationEmail::dispatch(
                        $dispatch['logId'],
                        $dispatch['userId'],
                        $dispatch['title'],
                        $dispatch['message'],
                        $dispatch['actionUrl'],
                    );
                }
            });
        }
    }

    /**
     * @return array{in_app: bool, email: bool}
     */
    public function channelsFor(User $user, string $type): array
    {
        $typePreference = DB::table('user_notification_preferences')
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->first();

        return [
            'in_app' => (bool) $user->notify_push
                && ($typePreference === null || (bool) $typePreference->in_app),
            'email' => in_array($type, self::EMAIL_TYPES, true)
                && (bool) $user->notify_email
                && ($typePreference === null
                    ? $type !== 'comment'
                    : (bool) $typePreference->email),
        ];
    }

    private function actionUrl(Task|Item|null $source): ?string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        return match (true) {
            $source instanceof Task => $base.'/tasks?open='.$source->getKey(),
            $source instanceof Item => $base.'/items?open='.$source->getKey(),
            default => null,
        };
    }

    private function dueDateForLog(Task|Item|null $source): string
    {
        $due = $source?->getAttribute('due_date');
        if ($due instanceof \DateTimeInterface) {
            return $due->format('Y-m-d');
        }

        return is_string($due) && $due !== '' ? substr($due, 0, 10) : now()->toDateString();
    }

    private function createPendingEmailLog(
        int $userId,
        string $sourceType,
        int $sourceId,
        ?int $reminderPeriodId,
        string $dueDate,
        string $title,
        string $message,
    ): int {
        $now = now();
        $keys = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'user_id' => $userId,
            'reminder_period_id' => $reminderPeriodId,
            'due_date' => $dueDate,
            'channel' => 'email',
        ];
        $existing = DB::table('notification_logs')->where($keys)->first();
        $values = [
            'title' => $title,
            'message' => $message,
            'scheduled_for' => $now,
            'sent_at' => null,
            'status' => 'pending',
            'error_message' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('notification_logs')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('notification_logs')->insertGetId(array_merge($keys, $values, [
            'read_at' => null,
            'created_at' => $now,
        ]));
    }
}
