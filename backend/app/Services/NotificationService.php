<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NotificationService
{
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
    ): void {
        $actorId = $actor?->getKey() ?? auth()->user()?->getAuthIdentifier();
        $recipients = $users instanceof User ? [$users] : $users;
        $userIds = collect($recipients)
            ->filter(fn ($user): bool => $user instanceof User)
            ->map(fn (User $user): int => (int) $user->getKey())
            ->filter(fn (int $id): bool => $id > 0 && $id !== (int) $actorId)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $now = now();
        $encodedData = $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR);
        $rows = array_map(fn (int $userId): array => [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'source_type' => $source instanceof Task ? 'task' : ($source instanceof Item ? 'item' : null),
            'source_id' => $source?->getKey(),
            'data' => $encodedData,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        DB::table('user_notifications')->insert($rows);
    }
}
