<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title', 'description', 'client_id', 'created_by', 'due_date', 'status', 'last_renewed_at',
    'is_recurring', 'recurrence_type', 'recurrence_interval',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active', 'renewed', 'expired', 'cancelled'];

    public const CHANGEABLE_STATUSES = ['active', 'cancelled', 'expired'];

    public const RECURRENCE_TYPES = ['monthly', 'quarterly', 'yearly', 'custom_days'];

    public const SORTABLE = ['due_date', 'title', 'status', 'created_at'];

    public const EAGER_LOAD = ['client', 'creator', 'departments', 'owners', 'reminderPeriods'];

    protected $attributes = [
        'status' => 'active',
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
            'last_renewed_at' => 'datetime',
            'is_recurring' => 'boolean',
            'recurrence_interval' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'item_departments')->withTimestamps();
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'item_owners')->withTimestamps();
    }

    public function reminderPeriods(): BelongsToMany
    {
        return $this->belongsToMany(ReminderPeriod::class, 'item_reminder_periods')->withTimestamps();
    }

    /**
     * Admins and managers see every item. Staff only see the items they own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin' || $user->role === 'manager') {
            return $query;
        }

        return $query->whereHas('owners', fn (Builder $owners): Builder => $owners->where('users.id', $user->getKey()));
    }

    public function isOwnedBy(User $user): bool
    {
        if ($this->relationLoaded('owners')) {
            return $this->owners->contains(fn (User $owner): bool => $owner->getKey() === $user->getKey());
        }

        return $this->owners()->whereKey($user->getKey())->exists();
    }
}
