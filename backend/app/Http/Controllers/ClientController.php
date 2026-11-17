<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanFilters;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    use NormalizesBooleanFilters;

    public function __construct(private readonly ClientService $clients) {}

    public function index(Request $request)
    {
        $this->normalizeBooleanFilters($request, ['is_active', 'with_trashed']);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'with_trashed' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(ClientService::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $includeTrashed = ($filters['with_trashed'] ?? false) && $request->user()->role === 'admin';

        return ClientResource::collection($this->clients->paginate($filters, $includeTrashed));
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'string', 'max:255']]);

        $clients = $this->clients->options($validated['search'] ?? null)
            ->map(static fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
            ]);

        return response()->json(['data' => $clients]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->clients->create($request->user(), $request->validated(), $request->ip());

        return ClientResource::make($client)->response()->setStatusCode(201);
    }

    public function show(int $client): ClientResource
    {
        return ClientResource::make(Client::withTrashed()->findOrFail($client));
    }

    public function update(UpdateClientRequest $request, int $client): ClientResource
    {
        $target = Client::withTrashed()->findOrFail($client);

        return ClientResource::make($this->clients->update($request->user(), $target, $request->validated(), $request->ip()));
    }

    public function activate(Request $request, int $client): JsonResponse
    {
        return $this->setActive($request, $client, true);
    }

    public function deactivate(Request $request, int $client): JsonResponse
    {
        return $this->setActive($request, $client, false);
    }

    public function destroy(Request $request, int $client): JsonResponse
    {
        $target = Client::withTrashed()->findOrFail($client);
        $usage = $this->clients->delete($request->user(), $target, $request->ip());

        return response()->json([
            'message' => 'Client deleted.',
            'meta' => $usage,
        ]);
    }

    public function restore(Request $request, int $client): ClientResource
    {
        $target = Client::onlyTrashed()->findOrFail($client);

        return ClientResource::make($this->clients->restore($request->user(), $target, $request->ip()));
    }

    private function setActive(Request $request, int $client, bool $active): JsonResponse
    {
        $target = Client::withTrashed()->findOrFail($client);
        $usage = $this->clients->setActive($request->user(), $target, $active, $request->ip());

        return response()->json([
            'data' => ClientResource::make($target->refresh())->resolve($request),
            'message' => $active ? 'Client activated.' : 'Client deactivated.',
            'meta' => $usage,
        ]);
    }
}
