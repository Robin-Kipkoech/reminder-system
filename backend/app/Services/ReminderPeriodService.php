<?php

namespace App\Services;

use App\Models\ReminderPeriod;
use App\Models\User;
use App\Services\Concerns\RejectsDuplicateNames;
use App\Services\Concerns\WritesAuditLog;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReminderPeriodService
{
    use RejectsDuplicateNames, WritesAuditLog;

    public const SORTABLE = ['label', 'offset_days', 'is_active', 'created_at'];

    private const ENTITY = 'reminder_period';

    private const AUDIT_FIELDS = ['label', 'offset_days', 'is_active'];

    /**
     * @param  array{search?: string, is_active?: bool|null, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = ReminderPeriod::query();

        if (! empty($filters['search'])) {
            $term = Search::pattern($filters['search']);

            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('label', 'like', $term);
            });
        }

        if (($filters['is_active'] ?? null) !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->orderBy($filters['sort'] ?? 'offset_days', $filters['direction'] ?? 'asc')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function options(?string $search): Collection
    {
        return ReminderPeriod::query()
            ->where('is_active', true)
            ->when($search, fn (Builder $query): Builder => $query->where('label', 'like', Search::pattern($search)))
            ->orderByDesc('offset_days')
            ->get(['id', 'label', 'offset_days']);
    }

    public function create(User $actor, array $attributes, ?string $ip): ReminderPeriod
    {
        return DB::transaction(function () use ($actor, $attributes, $ip): ReminderPeriod {
            $period = $this->guardUniqueName(fn (): ReminderPeriod => ReminderPeriod::create($attributes), 'offset_days');

            $this->audit($actor, 'created', self::ENTITY, $period, null, $this->values($period), $ip);

            return $period;
        });
    }

    public function update(User $actor, ReminderPeriod $period, array $attributes, ?string $ip): ReminderPeriod
    {
        return DB::transaction(function () use ($actor, $period, $attributes, $ip): ReminderPeriod {
            $oldValues = $this->values($period);

            $this->guardUniqueName(function () use ($period, $attributes): void {
                $period->fill($attributes)->save();
            }, 'offset_days');

            $this->audit($actor, 'updated', self::ENTITY, $period, $oldValues, $this->values($period->refresh()), $ip);

            return $period;
        });
    }

    public function setActive(User $actor, ReminderPeriod $period, bool $active, ?string $ip): ReminderPeriod
    {
        return DB::transaction(function () use ($actor, $period, $active, $ip): ReminderPeriod {
            $oldValues = $this->values($period);

            $period->is_active = $active;
            $period->save();

            $this->audit($actor, $active ? 'activated' : 'deactivated', self::ENTITY, $period, $oldValues, $this->values($period->refresh()), $ip);

            return $period;
        });
    }

    public function delete(User $actor, ReminderPeriod $period, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $period, $ip): void {
            $this->assertUnused($period);

            $oldValues = $this->values($period);

            $period->delete();

            $this->audit($actor, 'deleted', self::ENTITY, $period, $oldValues, $this->values($period), $ip);
        });
    }

    private function assertUnused(ReminderPeriod $period): void
    {
        $inUse = DB::table('task_reminder_periods')->where('reminder_period_id', $period->getKey())->exists()
            || DB::table('item_reminder_periods')->where('reminder_period_id', $period->getKey())->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'reminder_period' => ['This reminder period is in use. Deactivate it instead.'],
            ]);
        }
    }

    private function values(ReminderPeriod $period): array
    {
        return array_intersect_key($period->getAttributes(), array_flip(self::AUDIT_FIELDS));
    }
}
