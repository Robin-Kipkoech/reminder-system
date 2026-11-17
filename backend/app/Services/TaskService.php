<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Concerns\WritesAuditLog;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskService
{
    use WritesAuditLog;

    private const ENTITY = 'task';

    private const AUDIT_FIELDS = [
        'title', 'description', 'department_id', 'client_id', 'due_date', 'visibility', 'status',
        'is_recurring', 'recurrence_type', 'recurrence_interval', 'parent_task_id', 'completed_by',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array{search?: string, status?: string, client_id?: int, department_id?: int, assignee_id?: int, mine?: bool, due_from?: string, due_to?: string, with_trashed?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Task::query()
            ->visibleTo($actor)
            ->with(Task::EAGER_LOAD)
            ->withCount('comments');

        if (($filters['with_trashed'] ?? false) && $actor->role === 'admin') {
            $query->withTrashed();
        }

        if (! empty($filters['search'])) {
            $term = Search::pattern($filters['search']);

            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->whereHas('assignees', fn (Builder $builder): Builder => $builder->where('users.id', $filters['assignee_id']));
        }

        if ($filters['mine'] ?? false) {
            $query->where(function (Builder $builder) use ($actor): void {
                $builder->where('created_by', $actor->getKey())
                    ->orWhereHas('assignees', fn (Builder $assignees): Builder => $assignees->where('users.id', $actor->getKey()));
            });
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('due_date', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('due_date', '<=', $filters['due_to']);
        }

        $status = $filters['status'] ?? null;

        if (! empty($status)) {
            if ($status === 'overdue') {
                $query->where('status', '!=', 'done')
                    ->whereDate('due_date', '<', Carbon::today());
            } else {
                $query->where('status', $status);
            }
        }

        return $query->orderBy($filters['sort'] ?? 'due_date', $filters['direction'] ?? 'asc')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function create(User $actor, array $attributes, ?string $ip): Task
    {
        return DB::transaction(function () use ($actor, $attributes, $ip): Task {
            $task = new Task([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'department_id' => $attributes['department_id'],
                'client_id' => $attributes['client_id'],
                'due_date' => $attributes['due_date'],
                'visibility' => $attributes['visibility'] ?? 'everyone',
                'status' => 'open',
                'is_recurring' => (bool) ($attributes['is_recurring'] ?? false),
            ]);

            $this->applyRecurrence($task, $attributes);

            // created_by is always the authenticated user, whatever the client sends.
            $task->created_by = $actor->getKey();
            $task->save();

            $assigneeChanges = $task->assignees()->sync($attributes['assignee_ids']);
            $task->reminderPeriods()->sync($attributes['reminder_period_ids'] ?? []);

            $this->audit($actor, 'created', self::ENTITY, $task, null, $this->values($task), $ip);
            $this->notifyAssigned(
                $actor,
                User::query()->whereIn('id', $assigneeChanges['attached'])->get(),
                $task,
            );

            return $this->reload($task);
        });
    }

    public function update(User $actor, Task $task, array $attributes, ?string $ip): Task
    {
        return DB::transaction(function () use ($actor, $task, $attributes, $ip): Task {
            $oldValues = $this->values($task);

            $task->fill([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'department_id' => $attributes['department_id'],
                'client_id' => $attributes['client_id'],
                'due_date' => $attributes['due_date'],
                'visibility' => $attributes['visibility'],
            ]);

            $task->is_recurring = (bool) $attributes['is_recurring'];
            $this->applyRecurrence($task, $attributes);

            $task->save();

            $assigneeChanges = $task->assignees()->sync($attributes['assignee_ids']);
            $task->reminderPeriods()->sync($attributes['reminder_period_ids'] ?? []);

            $this->audit($actor, 'updated', self::ENTITY, $task, $oldValues, $this->values($task->refresh()), $ip);
            $this->notifyAssigned(
                $actor,
                User::query()->whereIn('id', $assigneeChanges['attached'])->get(),
                $task,
            );

            return $this->reload($task);
        });
    }

    /**
     * @return array{task: Task, next_task: Task|null}
     */
    public function changeStatus(User $actor, Task $task, string $status, ?string $ip): array
    {
        if ($status === 'done') {
            return $this->complete($actor, $task, $ip);
        }

        return DB::transaction(function () use ($actor, $task, $status, $ip): array {
            $oldValues = $this->values($task);

            $task->status = $status;
            $task->completed_at = null;
            $task->completed_by = null;
            $task->save();

            $this->audit($actor, 'status-changed', self::ENTITY, $task, $oldValues, $this->values($task->refresh()), $ip);

            return ['task' => $this->reload($task), 'next_task' => null];
        });
    }

    public function updateDueDate(User $actor, Task $task, string $dueDate, ?string $ip): Task
    {
        return DB::transaction(function () use ($actor, $task, $dueDate, $ip): Task {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $oldDate = Carbon::parse($locked->due_date)->toDateString();
            $locked->due_date = $dueDate;
            $locked->save();
            $this->audit($actor, 'due-date-changed', self::ENTITY, $locked, ['due_date' => $oldDate], [
                'due_date' => $dueDate,
            ], $ip);

            return $this->reload($locked);
        });
    }

    /**
     * Complete a task and, for recurring tasks, create the next occurrence once.
     *
     * @return array{task: Task, next_task: Task|null}
     */
    public function complete(User $actor, Task $task, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $task, $ip): array {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            // Calling complete twice must not spawn a second occurrence.
            if ($locked->status === 'done') {
                return ['task' => $this->reload($locked), 'next_task' => null];
            }

            $oldValues = $this->values($locked);

            $locked->status = 'done';
            $locked->completed_at = Carbon::now();
            $locked->completed_by = $actor->getKey();
            $locked->save();

            $this->audit($actor, 'completed', self::ENTITY, $locked, $oldValues, $this->values($locked->refresh()), $ip);
            $this->notifications->notify(
                $locked->creator()->get(),
                'completed',
                'Task completed',
                "{$actor->name} completed your task: {$locked->title}",
                $locked,
                ['task_id' => $locked->getKey()],
                $actor,
            );

            $next = $locked->is_recurring ? $this->createNextOccurrence($actor, $locked, $ip) : null;

            return ['task' => $this->reload($locked), 'next_task' => $next === null ? null : $this->reload($next)];
        });
    }

    public function delete(User $actor, Task $task, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $task, $ip): void {
            $oldValues = $this->values($task);

            $task->delete();

            $this->audit($actor, 'deleted', self::ENTITY, $task, $oldValues, $this->values($task), $ip);
        });
    }

    public function addComment(User $actor, Task $task, string $body, ?string $ip): TaskComment
    {
        return DB::transaction(function () use ($actor, $task, $body, $ip): TaskComment {
            $comment = $task->comments()->create([
                'user_id' => $actor->getKey(),
                'body' => $body,
            ]);

            $this->audit($actor, 'comment-added', self::ENTITY, $task, null, [
                'comment_id' => $comment->getKey(),
                'body' => $comment->body,
            ], $ip);
            $this->notifyCommentRecipients($actor, $task, $comment->body);

            return $comment->load('author');
        });
    }

    public function deleteComment(User $actor, TaskComment $comment, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $comment, $ip): void {
            $taskId = $comment->task_id;
            $oldValues = [
                'comment_id' => $comment->getKey(),
                'body' => $comment->body,
            ];

            $comment->delete();

            $this->audit($actor, 'comment-deleted', self::ENTITY, $taskId, $oldValues, null, $ip);
        });
    }

    private function createNextOccurrence(User $actor, Task $task, ?string $ip): Task
    {
        $next = new Task([
            'title' => $task->title,
            'description' => $task->description,
            'department_id' => $task->department_id,
            'client_id' => $task->client_id,
            'due_date' => $this->nextDueDate($task)->toDateString(),
            'visibility' => $task->visibility,
            'status' => 'open',
            'is_recurring' => $task->is_recurring,
            'recurrence_type' => $task->recurrence_type,
            'recurrence_interval' => $task->recurrence_interval,
            'parent_task_id' => $task->getKey(),
        ]);

        // The occurrence belongs to whoever created the original task.
        $next->created_by = $task->created_by;
        $next->save();

        $next->assignees()->sync($task->assignees()->pluck('users.id')->all());
        $next->reminderPeriods()->sync($task->reminderPeriods()->pluck('reminder_periods.id')->all());

        $this->audit($actor, 'created', self::ENTITY, $next, null, $this->values($next), $ip);

        return $next;
    }

    private function nextDueDate(Task $task): Carbon
    {
        $interval = max(1, (int) ($task->recurrence_interval ?: 1));
        $today = Carbon::today();

        $advance = match ($task->recurrence_type) {
            'weekly' => fn (Carbon $date): Carbon => $date->addWeeks($interval),
            'monthly' => fn (Carbon $date): Carbon => $date->addMonthNoOverflow($interval),
            'yearly' => fn (Carbon $date): Carbon => $date->addYearNoOverflow($interval),
            default => fn (Carbon $date): Carbon => $date->addDays($interval),
        };

        $next = $advance(Carbon::parse($task->due_date)->startOfDay());

        // Never hand back an occurrence that is already in the past.
        while ($next->lessThan($today)) {
            $next = $advance($next);
        }

        return $next;
    }

    private function applyRecurrence(Task $task, array $attributes): void
    {
        if (! $task->is_recurring) {
            $task->recurrence_type = null;
            $task->recurrence_interval = null;

            return;
        }

        $task->recurrence_type = $attributes['recurrence_type'] ?? null;

        $task->recurrence_interval = $task->recurrence_type === 'custom_days'
            ? (int) ($attributes['recurrence_interval'] ?? 1)
            : 1;
    }

    /**
     * @param  iterable<User>  $recipients
     */
    private function notifyAssigned(User $actor, iterable $recipients, Task $task): void
    {
        $this->notifications->notify(
            $recipients,
            'assigned',
            'Task assigned',
            "{$actor->name} assigned you a task: {$task->title}",
            $task,
            ['task_id' => $task->getKey()],
            $actor,
        );
    }

    private function notifyCommentRecipients(User $actor, Task $task, string $body): void
    {
        preg_match_all('/@\[([^\]]+)\]\(user:(\d+)\)/u', $body, $matches, PREG_SET_ORDER);
        $mentionNames = [];

        foreach ($matches as $match) {
            $mentionNames[(int) $match[2]] = $match[1];
        }

        $mentionedUsers = $mentionNames === []
            ? collect()
            : User::query()->whereIn('id', array_keys($mentionNames))->get();
        $visibleMentions = $mentionedUsers->filter(function (User $user) use ($mentionNames, $task): bool {
            return $user->name === $mentionNames[$user->getKey()]
                && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists();
        });
        $mentionedIds = $visibleMentions->pluck('id')->all();

        $task->loadMissing(['assignees', 'creator']);
        $commentRecipients = $task->assignees
            ->concat([$task->creator])
            ->filter(fn (?User $user): bool => $user !== null && ! in_array($user->getKey(), $mentionedIds, true));

        $this->notifications->notify(
            $commentRecipients,
            'comment',
            'New task comment',
            "{$actor->name} commented on {$task->title}",
            $task,
            ['task_id' => $task->getKey()],
            $actor,
        );
        $this->notifications->notify(
            $visibleMentions,
            'mention',
            'You were mentioned in a task',
            "{$actor->name} mentioned you on {$task->title}",
            $task,
            ['task_id' => $task->getKey()],
            $actor,
        );
    }

    private function reload(Task $task): Task
    {
        return $task->load(Task::EAGER_LOAD)->loadCount('comments');
    }

    private function values(Task $task): array
    {
        $values = array_intersect_key($task->getAttributes(), array_flip(self::AUDIT_FIELDS));

        if (isset($values['due_date']) && $values['due_date'] instanceof \DateTimeInterface) {
            $values['due_date'] = $values['due_date']->format('Y-m-d');
        }

        return $values;
    }
}
