<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Services\Concerns\RejectsDuplicateNames;
use App\Services\Concerns\WritesAuditLog;
use App\Support\RecordUsage;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClientService
{
    use RejectsDuplicateNames, WritesAuditLog;

    public const SORTABLE = ['name', 'contact_person', 'email', 'phone', 'is_active', 'created_at', 'updated_at'];

    private const ENTITY = 'client';

    private const AUDIT_FIELDS = ['name', 'contact_person', 'email', 'phone', 'address', 'notes', 'is_active'];

    /**
     * @param  array{search?: string, is_active?: bool|null, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function paginate(array $filters, bool $includeTrashed): LengthAwarePaginator
    {
        $query = Client::query();

        if ($includeTrashed) {
            $query->withTrashed();
        }

        if (! empty($filters['search'])) {
            $term = Search::pattern($filters['search']);

            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('contact_person', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
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
        return Client::query()
            ->where('is_active', true)
            ->when($search, fn (Builder $query): Builder => $query->where('name', 'like', Search::pattern($search)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function create(User $actor, array $attributes, ?string $ip): Client
    {
        return DB::transaction(function () use ($actor, $attributes, $ip): Client {
            $client = $this->guardUniqueName(fn (): Client => Client::create($attributes));

            $this->audit($actor, 'created', self::ENTITY, $client, null, $this->values($client), $ip);

            return $client;
        });
    }

    public function update(User $actor, Client $client, array $attributes, ?string $ip): Client
    {
        return DB::transaction(function () use ($actor, $client, $attributes, $ip): Client {
            $oldValues = $this->values($client);

            $this->guardUniqueName(function () use ($client, $attributes): void {
                $client->fill($attributes)->save();
            });

            $this->audit($actor, 'updated', self::ENTITY, $client, $oldValues, $this->values($client->refresh()), $ip);

            return $client;
        });
    }

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public function setActive(User $actor, Client $client, bool $active, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $client, $active, $ip): array {
            $oldValues = $this->values($client);

            $client->is_active = $active;
            $client->save();

            $this->audit($actor, $active ? 'activated' : 'deactivated', self::ENTITY, $client, $oldValues, $this->values($client->refresh()), $ip);

            return RecordUsage::forClient($client->getKey());
        });
    }

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public function delete(User $actor, Client $client, ?string $ip): array
    {
        return DB::transaction(function () use ($actor, $client, $ip): array {
            $oldValues = $this->values($client);
            $usage = RecordUsage::forClient($client->getKey());

            $client->delete();

            $this->audit($actor, 'deleted', self::ENTITY, $client, $oldValues, $this->values($client), $ip);

            return $usage;
        });
    }

    public function restore(User $actor, Client $client, ?string $ip): Client
    {
        return DB::transaction(function () use ($actor, $client, $ip): Client {
            $oldValues = $this->values($client);

            $client->restore();

            $this->audit($actor, 'restored', self::ENTITY, $client, $oldValues, $this->values($client->refresh()), $ip);

            return $client;
        });
    }

    private function values(Client $client): array
    {
        return array_intersect_key($client->getAttributes(), array_flip(self::AUDIT_FIELDS));
    }
}
