<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\DueDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const OPEN_TASK_STATUSES = ['open', 'in_progress'];

    private const MY_TASK_LIMIT = 8;

    private const UPCOMING_LIMIT = 15;

    private const UPCOMING_WINDOW_DAYS = 14;

    /**
     * Everything the dashboard shows is scoped to what this user may already see:
     * tasks go through Task::visibleTo and items through Item::visibleTo.
     */
    public function for(User $user): array
    {
        $today = DueDates::today();
        $staff = $user->role === 'staff';

        return [
            'stats' => $this->stats($user, $today),
            'my_tasks' => $this->myTasks($user),
            'upcoming' => $this->upcoming($user),
            'items_expiring' => $staff ? null : $this->itemsExpiring($user),
            'workload' => $staff ? null : $this->workload($user),
            'by_client' => $staff ? null : $this->byClient($user),
            'by_department' => $staff ? null : $this->byDepartment($user),
            'recent_activity' => $staff ? null : $this->recentActivity(),
        ];
    }

    private function stats(User $user, Carbon $today): array
    {
        $weekAhead = $today->copy()->addDays(7)->toDateString();
        $monthAhead = $today->copy()->addDays(30)->toDateString();
        $todayDate = $today->toDateString();

        $openTasks = fn (): Builder => Task::query()
            ->visibleTo($user)
            ->whereIn('status', self::OPEN_TASK_STATUSES);

        $activeItems = fn (): Builder => Item::query()
            ->visibleTo($user)
            ->where('status', 'active');

        return [
            'overdue_tasks' => (clone $openTasks())
                ->whereDate('due_date', '<', $todayDate)
                ->count(),
            'due_today' => (clone $openTasks())
                ->whereDate('due_date', $todayDate)
                ->count(),
            'due_this_week' => (clone $openTasks())
                ->whereBetween('due_date', [$todayDate, $weekAhead])
                ->count(),
            'my_open_tasks' => (clone $openTasks())
                ->whereHas('assignees', fn (Builder $assignees): Builder => $assignees->where('users.id', $user->getKey()))
                ->count(),
            'overdue_items' => (clone $activeItems())
                ->whereDate('due_date', '<', $todayDate)
                ->count(),
            'items_expiring_30' => (clone $activeItems())
                ->whereBetween('due_date', [$todayDate, $monthAhead])
                ->count(),
        ];
    }

    private function myTasks(User $user): array
    {
        $tasks = Task::query()
            ->visibleTo($user)
            ->whereHas('assignees', fn (Builder $assignees): Builder => $assignees->where('users.id', $user->getKey()))
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->with(['client'])
            // Overdue dates sort first because they are the earliest dates.
            ->orderBy('due_date')
            ->limit(self::MY_TASK_LIMIT)
            ->get();

        return $tasks->map(fn (Task $task): array => [
            'id' => $task->id,
            'title' => $task->title,
            'due_date' => DueDates::toDateString($task->due_date),
            'days_until_due' => DueDates::daysUntil($task->due_date),
            'status' => $task->status,
            'is_overdue' => DueDates::isPast($task->due_date),
            'client' => [
                'id' => $task->client?->id,
                'name' => $task->client?->name,
            ],
        ])->values()->all();
    }

    private function upcoming(User $user): array
    {
        $horizon = DueDates::today()->copy()->addDays(self::UPCOMING_WINDOW_DAYS)->toDateString();

        $tasks = Task::query()
            ->visibleTo($user)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->whereDate('due_date', '<=', $horizon)
            ->with(['client'])
            ->orderBy('due_date')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn (Task $task): array => [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'client_name' => $task->client?->name,
                'due_date' => DueDates::toDateString($task->due_date),
                'days_until_due' => DueDates::daysUntil($task->due_date),
                'status' => $task->status,
                'is_overdue' => DueDates::isPast($task->due_date),
            ]);

        $items = Item::query()
            ->visibleTo($user)
            ->where('status', 'active')
            ->whereDate('due_date', '<=', $horizon)
            ->with(['client'])
            ->orderBy('due_date')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn (Item $item): array => [
                'type' => 'item',
                'id' => $item->id,
                'title' => $item->title,
                'client_name' => $item->client?->name,
                'due_date' => DueDates::toDateString($item->due_date),
                'days_until_due' => DueDates::daysUntil($item->due_date),
                'status' => $item->status,
                'is_overdue' => DueDates::isPast($item->due_date),
            ]);

        // The earliest 15 of the merged lists are always within the earliest 15 of each list.
        return $tasks->concat($items)
            ->sortBy('due_date')
            ->take(self::UPCOMING_LIMIT)
            ->values()
            ->all();
    }

    private function itemsExpiring(User $user): array
    {
        return [
            'within_7' => $this->expiringItems($user, 0, 7),
            'within_30' => $this->expiringItems($user, 8, 30),
            'within_60' => $this->expiringItems($user, 31, 60),
        ];
    }

    private function expiringItems(User $user, int $fromDays, int $toDays): array
    {
        $from = DueDates::today()->copy()->addDays($fromDays)->toDateString();
        $to = DueDates::today()->copy()->addDays($toDays)->toDateString();

        return Item::query()
            ->visibleTo($user)
            ->where('status', 'active')
            ->whereBetween('due_date', [$from, $to])
            ->with(['client'])
            ->orderBy('due_date')
            ->limit(10)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'client_name' => $item->client?->name,
                'due_date' => DueDates::toDateString($item->due_date),
                'days_until_due' => DueDates::daysUntil($item->due_date),
            ])
            ->values()
            ->all();
    }

    private function workload(User $user): array
    {
        $today = DueDates::today()->toDateString();

        /** @var array<int, int> $open */
        $open = Task::query()
            ->visibleTo($user)
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->whereIn('tasks.status', self::OPEN_TASK_STATUSES)
            ->groupBy('task_assignees.user_id')
            ->selectRaw('task_assignees.user_id as user_id, COUNT(*) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        /** @var array<int, int> $overdue */
        $overdue = Task::query()
            ->visibleTo($user)
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->whereIn('tasks.status', self::OPEN_TASK_STATUSES)
            ->whereDate('tasks.due_date', '<', $today)
            ->groupBy('task_assignees.user_id')
            ->selectRaw('task_assignees.user_id as user_id, COUNT(*) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        if ($open === []) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', array_keys($open))
            ->pluck('name', 'id')
            ->all();

        $rows = [];

        foreach ($open as $userId => $openCount) {
            $rows[] = [
                'user' => [
                    'id' => (int) $userId,
                    'name' => $names[$userId] ?? 'Unknown user',
                ],
                'open_tasks' => $openCount,
                'overdue_tasks' => $overdue[$userId] ?? 0,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return $b['open_tasks'] <=> $a['open_tasks']
                ?: ($b['overdue_tasks'] <=> $a['overdue_tasks'])
                ?: ($a['user']['name'] <=> $b['user']['name']);
        });

        return array_slice($rows, 0, 10);
    }

    private function byClient(User $user): array
    {
        $open = Task::query()
            ->visibleTo($user)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->pluck('total', 'client_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $items = Item::query()
            ->visibleTo($user)
            ->where('status', 'active')
            ->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->pluck('total', 'client_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        if ($open === [] && $items === []) {
            return [];
        }

        $names = Client::query()
            ->whereIn('id', array_unique([...array_keys($open), ...array_keys($items)]))
            ->pluck('name', 'id')
            ->all();

        $rows = [];

        foreach (array_unique([...array_keys($open), ...array_keys($items)]) as $clientId) {
            $rows[] = [
                'client' => [
                    'id' => (int) $clientId,
                    'name' => $names[$clientId] ?? 'Unknown client',
                ],
                'open_tasks' => $open[$clientId] ?? 0,
                'active_items' => $items[$clientId] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['open_tasks'] <=> $a['open_tasks']
            ?: ($b['active_items'] <=> $a['active_items'])
            ?: ($a['client']['name'] <=> $b['client']['name']));

        return array_slice($rows, 0, 8);
    }

    private function byDepartment(User $user): array
    {
        $open = Task::query()
            ->visibleTo($user)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->groupBy('department_id')
            ->selectRaw('department_id, COUNT(*) as total')
            ->pluck('total', 'department_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $items = Item::query()
            ->visibleTo($user)
            ->join('item_departments', 'item_departments.item_id', '=', 'items.id')
            ->where('items.status', 'active')
            ->groupBy('item_departments.department_id')
            ->selectRaw('item_departments.department_id as department_id, COUNT(*) as total')
            ->pluck('total', 'department_id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        if ($open === [] && $items === []) {
            return [];
        }

        $departments = Department::query()
            ->whereIn('id', array_unique([...array_keys($open), ...array_keys($items)]))
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = $departments->map(fn (Department $department): array => [
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],
            'open_tasks' => $open[$department->id] ?? 0,
            'active_items' => $items[$department->id] ?? 0,
        ])->all();

        usort($rows, fn (array $a, array $b): int => $b['open_tasks'] <=> $a['open_tasks']
            ?: ($b['active_items'] <=> $a['active_items'])
            ?: ($a['department']['name'] <=> $b['department']['name']));

        return $rows;
    }

    private function recentActivity(): array
    {
        $rows = DB::table('audit_logs')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $titles = [];

        foreach ($rows->groupBy('entity_type') as $type => $group) {
            $ids = $group->pluck('entity_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $titles[$type] = AuditTrail::titlesFor((string) $type, $ids);
        }

        $actorIds = $rows->pluck('user_id')->filter(fn ($id): bool => $id !== null)->unique()->values()->all();

        $actors = $actorIds === []
            ? collect()
            : User::query()->withTrashed()->whereIn('id', $actorIds)->pluck('name', 'id');

        return $rows->map(function ($row) use ($titles, $actors): array {
            $type = (string) $row->entity_type;
            $entityId = (int) $row->entity_id;

            $title = $titles[$type][$entityId] ?? null;

            if ($title === null) {
                $title = AuditTrail::titleFrom($row->new_values, $row->old_values);
            }

            return [
                'id' => (int) $row->id,
                'actor' => $row->user_id === null ? null : [
                    'id' => (int) $row->user_id,
                    'name' => $actors[$row->user_id] ?? 'Unknown user',
                ],
                'action' => (string) $row->action,
                'entity_type' => $type,
                'entity_id' => $entityId,
                'entity_title' => $title,
                'created_at' => $row->created_at,
            ];
        })->values()->all();
    }
}
