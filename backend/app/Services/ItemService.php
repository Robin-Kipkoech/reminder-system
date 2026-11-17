<?php

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use App\Services\Concerns\WritesAuditLog;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemService
{
    use WritesAuditLog;

    private const ENTITY = 'item';

    private const AUDIT_FIELDS = [
        'title', 'description', 'client_id', 'due_date', 'status', 'is_recurring',
        'recurrence_type', 'recurrence_interval', 'last_renewed_at',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array{search?: string, status?: string, client_id?: int, department_id?: int, owner_id?: int, mine?: bool, due_from?: string, due_to?: string, with_trashed?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Item::query()
            ->visibleTo($actor)
            ->with(Item::EAGER_LOAD);

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
            $query->whereHas('departments', fn (Builder $builder): Builder => $builder->where('departments.id', $filters['department_id']));
        }

        if (! empty($filters['owner_id'])) {
            $query->whereHas('owners', fn (Builder $builder): Builder => $builder->where('users.id', $filters['owner_id']));
        }

        if ($filters['mine'] ?? false) {
            $query->whereHas('owners', fn (Builder $builder): Builder => $builder->where('users.id', $actor->getKey()));
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
                $query->where('status', 'active')
                    ->whereDate('due_date', '<', Carbon::today());
            } else {
                $query->where('status', $status);
            }
        }

        return $query->orderBy($filters['sort'] ?? 'due_date', $filters['direction'] ?? 'asc')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function create(User $actor, array $attributes, ?string $ip): Item
    {
        return DB::transaction(function () use ($actor, $attributes, $ip): Item {
            $item = new Item([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'client_id' => $attributes['client_id'],
                'due_date' => $attributes['due_date'],
                // status is owned by the server: a new item always starts active.
                'status' => 'active',
                'is_recurring' => (bool) ($attributes['is_recurring'] ?? false),
            ]);

            $this->applyRecurrence($item, $attributes);

            // created_by is always the authenticated user, whatever the client sends.
            $item->created_by = $actor->getKey();
            $item->save();

            $newOwners = $this->syncRelations($item, $attributes);

            $this->audit($actor, 'created', self::ENTITY, $item, null, $this->values($item), $ip);
            $this->notifyOwners($actor, $newOwners, $item);

            return $this->reload($item);
        });
    }

    public function update(User $actor, Item $item, array $attributes, ?string $ip): Item
    {
        return DB::transaction(function () use ($actor, $item, $attributes, $ip): Item {
            $oldValues = $this->values($item);

            $item->fill([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'client_id' => $attributes['client_id'],
                // Past due dates stay editable so a wrongly entered date can be fixed.
                'due_date' => $attributes['due_date'],
            ]);

            $item->is_recurring = (bool) $attributes['is_recurring'];
            $this->applyRecurrence($item, $attributes);

            $item->save();

            $newOwners = $this->syncRelations($item, $attributes);

            $this->audit($actor, 'updated', self::ENTITY, $item, $oldValues, $this->values($item->refresh()), $ip);
            $this->notifyOwners($actor, $newOwners, $item);

            return $this->reload($item);
        });
    }

    public function delete(User $actor, Item $item, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $item, $ip): void {
            $oldValues = $this->values($item);

            $item->delete();

            $this->audit($actor, 'deleted', self::ENTITY, $item, $oldValues, $this->values($item), $ip);
        });
    }

    /**
     * Push the due date one renewal forward and restart the reminders, which are
     * deduplicated per due date.
     */
    public function renew(User $actor, Item $item, string $dueDate, ?string $ip): Item
    {
        return DB::transaction(function () use ($actor, $item, $dueDate, $ip): Item {
            $locked = Item::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            $oldValues = $this->values($locked);

            if (Carbon::parse($dueDate)->startOfDay()->lessThanOrEqualTo(Carbon::parse($locked->due_date)->startOfDay())) {
                throw ValidationException::withMessages([
                    'due_date' => ['The new due date must be after the current due date.'],
                ]);
            }

            $oldDueDate = $this->dueDateString($locked);

            $locked->due_date = $dueDate;
            $locked->status = 'active';
            $locked->last_renewed_at = Carbon::now();
            $locked->save();

            $newValues = $this->values($locked);

            // The renewal is recorded with the old and new due date so a due date
            // change can be traced even though nothing else about the item moved.
            $this->audit($actor, 'renewed', self::ENTITY, $locked, [
                ...$oldValues,
                'due_date' => $oldDueDate,
            ], [
                ...$newValues,
                'due_date' => $this->dueDateString($locked),
            ], $ip);

            return $this->reload($locked);
        });
    }

    public function updateDueDate(User $actor, Item $item, string $dueDate, ?string $ip): Item
    {
        return DB::transaction(function () use ($actor, $item, $dueDate, $ip): Item {
            $locked = Item::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $oldDate = $this->dueDateString($locked);
            $locked->due_date = $dueDate;
            $locked->save();
            $this->audit($actor, 'due-date-changed', self::ENTITY, $locked, ['due_date' => $oldDate], [
                'due_date' => $dueDate,
            ], $ip);

            return $this->reload($locked);
        });
    }

    public function changeStatus(User $actor, Item $item, string $status, ?string $ip): Item
    {
        return DB::transaction(function () use ($actor, $item, $status, $ip): Item {
            $locked = Item::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            $oldValues = $this->values($locked);

            $locked->status = $status;
            $locked->save();

            $this->audit($actor, 'status-changed', self::ENTITY, $locked, $oldValues, $this->values($locked->refresh()), $ip);

            return $this->reload($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRelations(Item $item, array $attributes): array
    {
        $item->departments()->sync($attributes['department_ids']);
        $changes = $item->owners()->sync($attributes['owner_ids']);
        $item->reminderPeriods()->sync($attributes['reminder_period_ids'] ?? []);

        return User::query()->whereIn('id', $changes['attached'])->get()->all();
    }

    /**
     * @param  iterable<User>  $owners
     */
    private function notifyOwners(User $actor, iterable $owners, Item $item): void
    {
        $this->notifications->notify(
            $owners,
            'assigned',
            'Item assigned',
            "{$actor->name} assigned you an item: {$item->title}",
            $item,
            ['item_id' => $item->getKey()],
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyRecurrence(Item $item, array $attributes): void
    {
        if (! $item->is_recurring) {
            $item->recurrence_type = null;
            $item->recurrence_interval = null;

            return;
        }

        $item->recurrence_type = $attributes['recurrence_type'] ?? null;

        $item->recurrence_interval = $item->recurrence_type === 'custom_days'
            ? (int) ($attributes['recurrence_interval'] ?? 1)
            : 1;
    }

    private function reload(Item $item): Item
    {
        return $item->load(Item::EAGER_LOAD);
    }

    private function dueDateString(Item $item): string
    {
        return Carbon::parse($item->getRawOriginal('due_date'))->toDateString();
    }

    private function values(Item $item): array
    {
        $values = array_intersect_key($item->getAttributes(), array_flip(self::AUDIT_FIELDS));

        if (isset($values['due_date'])) {
            $values['due_date'] = Carbon::parse($values['due_date'])->toDateString();
        }

        return $values;
    }
}
