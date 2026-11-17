<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Services\Concerns\RejectsDuplicateNames;
use App\Services\Concerns\WritesAuditLog;
use App\Support\RecordUsage;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentService
{
    use RejectsDuplicateNames, WritesAuditLog;

    public const SORTABLE = ['name', 'is_active', 'created_at', 'updated_at'];

    private const ENTITY = 'department';

    private const AUDIT_FIELDS = ['name', 'description', 'is_active'];

    /**
     * @param  array{search?: string, is_active?: bool|null, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function paginate(array $filters, bool $includeTrashed): LengthAwarePaginator
    {
        $query = Department::query();

        if ($includeTrashed) {
            $query->withTrashed();
        }

        if (! empty($filters['search'])) {
            $term = Search::pattern($filters['search']);

            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (($filters['is_active'] ?? null) !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function options(?string $search): Collection
    {
        return Department::query()
            ->where('is_active', true)
            ->when($search, fn (Builder $query): Builder => $query->where('name', 'like', Search::pattern($search)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function create(User $actor, array $attributes, ?string $ip): Department
    {
        return DB::transaction(function () use ($actor, $attributes, $ip): Department {
            $department = $this->guardUniqueName(fn (): Department => Department::create($attributes));

            $this->audit($actor, 'created', self::ENTITY, $department, null, $this->values($department), $ip);

            return $department;
        });
    }

    public function update(User $actor, Department $department, array $attributes, ?string $ip): Department
    {
        return DB::transaction(function () use ($actor, $department, $attributes, $ip): Department {
            $oldValues = $this->values($department);

            $this->guardUniqueName(function () use ($department, $attributes): void {
                $department->fill($attributes)->save();
            });

            $this->audit($actor, 'updated', self::ENTITY, $department, $oldValues, $this->values($department->refresh()), $ip);

            return $department;
        });
    }

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public function setActive(User $actor, Department $department, bool $active, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $department, $active, $ip): array {
            $oldValues = $this->values($department);

            $department->is_active = $active;
            $department->save();

            $this->audit($actor, $active ? 'activated' : 'deactivated', self::ENTITY, $department, $oldValues, $this->values($department->refresh()), $ip);

            return RecordUsage::forDepartment($department->getKey());
        });
    }

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public function delete(User $actor, Department $department, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $department, $ip): array {
            $oldValues = $this->values($department);
            $usage = RecordUsage::forDepartment($department->getKey());

            $department->delete();

            $this->audit($actor, 'deleted', self::ENTITY, $department, $oldValues, $this->values($department), $ip);

            return $usage;
        });
    }

    public function restore(User $actor, Department $department, ?string $ip): Department
    {
        return DB::transaction(function () use ($actor, $department, $ip): Department {
            $oldValues = $this->values($department);

            $department->restore();

            $this->audit($actor, 'restored', self::ENTITY, $department, $oldValues, $this->values($department->refresh()), $ip);

            return $department;
        });
    }

    private function values(Department $department): array
    {
        return array_intersect_key($department->getAttributes(), array_flip(self::AUDIT_FIELDS));
    }
}
