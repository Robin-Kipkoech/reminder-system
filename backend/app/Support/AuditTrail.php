<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\ReminderPeriod;
use App\Models\Task;
use App\Models\User;

final class AuditTrail
{
    /**
     * Current titles for a set of entity ids of one type, including soft deleted
     * records, so a whole feed can be resolved with a single query per type.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    public static function titlesFor(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return match ($type) {
            'task' => Task::query()->withTrashed()->whereIn('id', $ids)->pluck('title', 'id')->all(),
            'item' => Item::query()->withTrashed()->whereIn('id', $ids)->pluck('title', 'id')->all(),
            'client' => Client::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'department' => Department::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'reminder_period' => ReminderPeriod::query()->whereIn('id', $ids)->pluck('label', 'id')->all(),
            'user' => User::query()->withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * Last resort for rows whose record no longer exists: read the title out of
     * the audit payload itself.
     */
    public static function titleFrom(?string $newValues, ?string $oldValues): ?string
    {
        foreach ([$newValues, $oldValues] as $values) {
            if ($values === null) {
                continue;
            }

            $decoded = json_decode((string) $values, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach (['title', 'name', 'label'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }

        return null;
    }
}
