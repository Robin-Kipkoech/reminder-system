<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    private const SAFE_AUDIT_FIELDS = ['name', 'email', 'phone', 'role', 'status', 'must_change_password'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $openTasks = DB::table('task_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->whereNull('tasks.deleted_at')
            ->whereIn('tasks.status', ['open', 'in_progress'])
            ->groupBy('task_assignees.user_id')
            ->selectRaw('task_assignees.user_id, COUNT(*) as total');

        $overdueTasks = DB::table('task_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->whereNull('tasks.deleted_at')
            ->whereIn('tasks.status', ['open', 'in_progress'])
            ->whereDate('tasks.due_date', '<', now()->toDateString())
            ->groupBy('task_assignees.user_id')
            ->selectRaw('task_assignees.user_id, COUNT(*) as total');

        $itemsInCharge = DB::table('item_owners')
            ->join('items', 'items.id', '=', 'item_owners.item_id')
            ->whereNull('items.deleted_at')
            ->where('items.status', 'active')
            ->groupBy('item_owners.user_id')
            ->selectRaw('item_owners.user_id, COUNT(*) as total');

        $query = User::query()
            ->leftJoinSub($openTasks, 'open_task_counts', 'open_task_counts.user_id', '=', 'users.id')
            ->leftJoinSub($overdueTasks, 'overdue_task_counts', 'overdue_task_counts.user_id', '=', 'users.id')
            ->leftJoinSub($itemsInCharge, 'item_counts', 'item_counts.user_id', '=', 'users.id')
            ->select('users.*')
            ->selectRaw('COALESCE(open_task_counts.total, 0) as open_tasks_count')
            ->selectRaw('COALESCE(overdue_task_counts.total, 0) as overdue_tasks_count')
            ->selectRaw('COALESCE(item_counts.total, 0) as items_in_charge_count');

        if ($filters['with_trashed'] ?? false) {
            $query->withTrashed();
        }

        if (! empty($filters['search'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $pattern = '%'.$search.'%';
                $builder->where('name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('phone', 'like', $pattern);
            });
        }

        foreach (['role', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate(min((int) ($filters['per_page'] ?? 15), 100));
    }

    public function options(?string $search): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->when($search, function (Builder $query, string $search): void {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $builder) use ($pattern): void {
                    $builder->where('name', 'like', $pattern)
                        ->orWhere('email', 'like', $pattern)
                        ->orWhere('phone', 'like', $pattern);
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    public function create(User $actor, array $attributes, ?string $ip): array
    {
        $generated = blank($attributes['password'] ?? null);
        $plainPassword = $generated
            ? Str::password(32, symbols: true)
            : $attributes['password'];
        unset($attributes['password']);

        return DB::transaction(function () use ($actor, $attributes, $plainPassword, $generated, $ip): array {
            $user = new User($attributes);
            $user->password = $plainPassword;
            $user->must_change_password = true;
            $user->save();

            $this->audit($actor, 'created', $user, null, $this->safeValues($user), $ip);

            return ['user' => $user, 'temporary_password' => $generated ? $plainPassword : null];
        });
    }

    public function update(User $actor, User $target, array $attributes, ?string $ip): User
    {
        return DB::transaction(function () use ($actor, $target, $attributes, $ip): User {
            $oldValues = $this->safeValues($target);

            if ($target->is($actor) && $target->role === 'admin' && ($attributes['role'] ?? 'admin') !== 'admin') {
                throw new HttpException(403, 'You cannot demote your own administrator account.');
            }

            $willSuspend = ($attributes['status'] ?? $target->status) === 'suspended'
                && $target->status !== 'suspended';
            $willDemote = ($attributes['role'] ?? $target->role) !== 'admin'
                && $target->role === 'admin'
                && $target->status === 'active';

            if ($willSuspend && $target->is($actor)) {
                throw new HttpException(403, 'You cannot suspend your own account.');
            }
            if ($willDemote) {
                $this->assertNotLastActiveAdmin($target, 'demote');
            }
            if ($willSuspend && $target->role === 'admin') {
                $this->assertNotLastActiveAdmin($target, 'suspend');
            }

            $target->fill($attributes);
            $target->save();

            if ($willSuspend) {
                $this->revokeAccess($target);
            }

            $this->audit($actor, 'updated', $target, $oldValues, $this->safeValues($target), $ip);

            return $target->refresh();
        });
    }

    public function suspend(User $actor, User $target, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $target, $ip): void {
            if ($target->is($actor)) {
                throw new HttpException(403, 'You cannot suspend your own account.');
            }
            if (! $target->trashed() && $target->status === 'active' && $target->role === 'admin') {
                $this->assertNotLastActiveAdmin($target, 'suspend');
            }

            $oldValues = $this->safeValues($target);
            $target->status = 'suspended';
            $target->save();
            $this->revokeAccess($target);
            $this->audit($actor, 'suspended', $target, $oldValues, $this->safeValues($target), $ip);
        });
    }

    public function activate(User $actor, User $target, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $target, $ip): void {
            $oldValues = $this->safeValues($target);
            $target->status = 'active';
            $target->save();
            $this->audit($actor, 'activated', $target, $oldValues, $this->safeValues($target), $ip);
        });
    }

    public function resetPassword(User $actor, User $target, ?string $ip): string
    {
        $plainPassword = Str::password(32, symbols: true);

        DB::transaction(function () use ($actor, $target, $plainPassword, $ip): void {
            $oldValues = $this->safeValues($target);
            $target->password = $plainPassword;
            $target->must_change_password = true;
            $target->save();
            $this->revokeAccess($target);
            $this->audit($actor, 'reset-password', $target, $oldValues, $this->safeValues($target), $ip);
        });

        return $plainPassword;
    }

    public function delete(User $actor, User $target, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $target, $ip): void {
            if ($target->is($actor)) {
                throw new HttpException(403, 'You cannot delete your own account.');
            }
            if (! $target->trashed() && $target->status === 'active' && $target->role === 'admin') {
                $this->assertNotLastActiveAdmin($target, 'delete');
            }

            $oldValues = $this->safeValues($target);
            $this->revokeAccess($target);
            $target->delete();
            $this->audit($actor, 'deleted', $target, $oldValues, $this->safeValues($target), $ip);
        });
    }

    public function restore(User $actor, User $target, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $target, $ip): void {
            $oldValues = $this->safeValues($target);
            $target->restore();
            $this->audit($actor, 'restored', $target, $oldValues, $this->safeValues($target), $ip);
        });
    }

    private function assertNotLastActiveAdmin(User $target, string $action): void
    {
        $activeAdminIds = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeAdminIds->count() <= 1 && $activeAdminIds->contains($target->getKey())) {
            $wording = [
                'suspend' => 'suspended',
                'delete' => 'deleted',
                'demote' => 'demoted',
            ][$action];

            throw ValidationException::withMessages([
                'user' => ["The last active administrator cannot be {$wording}."],
            ]);
        }
    }

    private function revokeAccess(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }

        $user->tokens()->delete();
    }

    private function safeValues(User $user): array
    {
        return array_intersect_key($user->getAttributes(), array_flip(self::SAFE_AUDIT_FIELDS));
    }

    private function audit(User $actor, string $action, User $target, ?array $old, ?array $new, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $target->getKey(),
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
