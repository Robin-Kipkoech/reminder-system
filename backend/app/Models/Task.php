<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title', 'description', 'department_id', 'client_id', 'created_by', 'due_date', 'visibility',
    'status', 'completed_at', 'completed_by', 'is_recurring', 'recurrence_type', 'recurrence_interval',
    'parent_task_id',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    public const VISIBILITIES = ['everyone', 'involved_only'];

    public const STATUSES = ['open', 'in_progress', 'done'];

    public const RECURRENCE_TYPES = ['daily', 'weekly', 'monthly', 'yearly', 'custom_days'];

    public const SORTABLE = ['due_date', 'title', 'status', 'created_at'];

    public const EAGER_LOAD = ['department', 'client', 'creator', 'assignees', 'reminderPeriods', 'completedBy'];

    protected $attributes = [
        'status' => 'open',
        'visibility' => 'everyone',
        'is_recurring' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'completed_at' => 'datetime',
            'is_recurring' => 'boolean',
            'recurrence_interval' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')->withTimestamps();
    }

    public function reminderPeriods(): BelongsToMany
    {
        return $this->belongsToMany(ReminderPeriod::class, 'task_reminder_periods')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * Admins see everything. Everyone else only sees tasks that are shared,
     * created by them, or assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($user): void {
            $builder->where('visibility', 'everyone')
                ->orWhere('created_by', $user->getKey())
                ->orWhereHas('assignees', fn (Builder $assignees): Builder => $assignees->where('users.id', $user->getKey()));
        });
    }

    public function isAssignedTo(User $user): bool
    {
        if ($this->relationLoaded('assignees')) {
            return $this->assignees->contains(fn (User $assignee): bool => $assignee->getKey() === $user->getKey());
        }

        return $this->assignees()->whereKey($user->getKey())->exists();
    }

    public function isInvolvedWith(User $user): bool
    {
        return $user->role === 'admin'
            || $this->created_by === $user->getKey()
            || $this->isAssignedTo($user);
    }

    public function canBeEditedBy(User $user): bool
    {
        return $this->isInvolvedWith($user);
    }

    public function canBeDeletedBy(User $user): bool
    {
        return $user->role === 'admin' || $this->created_by === $user->getKey();
    }
}
