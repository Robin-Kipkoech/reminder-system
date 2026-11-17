<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanFilters;
use App\Http\Requests\RenewItemRequest;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Requests\UpdateItemStatusRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Models\User;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    use NormalizesBooleanFilters;

    public function __construct(private readonly ItemService $items) {}

    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanFilters($request, ['mine', 'with_trashed']);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([...Item::STATUSES, 'overdue'])],
            'client_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'owner_id' => ['sometimes', 'integer'],
            'mine' => ['sometimes', 'boolean'],
            'due_from' => ['sometimes', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'with_trashed' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(Item::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $paginator = $this->items->paginate($filters, $request->user());

        return response()->json([
            'data' => ItemResource::collection($paginator)->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        Gate::authorize('create', Item::class);

        $item = $this->items->create($request->user(), $request->validated(), $request->ip());

        return response()->json(['data' => ItemResource::make($item)->resolve($request)], 201);
    }

    public function show(Request $request, int $item): JsonResponse
    {
        return response()->json([
            'data' => ItemResource::make($this->findVisible($request->user(), $item))->resolve($request),
        ]);
    }

    public function update(UpdateItemRequest $request, int $item): JsonResponse
    {
        $target = $this->findVisible($request->user(), $item);
        Gate::authorize('update', $target);

        $updated = $this->items->update($request->user(), $target, $request->validated(), $request->ip());

        return response()->json(['data' => ItemResource::make($updated)->resolve($request)]);
    }

    public function updateDueDate(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate(['due_date' => ['required', 'date_format:Y-m-d']]);
        $target = $this->findVisible($request->user(), $item);
        Gate::authorize('update', $target);
        $updated = $this->items->updateDueDate($request->user(), $target, $validated['due_date'], $request->ip());

        return response()->json(['data' => ItemResource::make($updated)->resolve($request)]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $target = $this->findVisible($request->user(), $item);
        Gate::authorize('delete', $target);

        $this->items->delete($request->user(), $target, $request->ip());

        return response()->json(['message' => 'Item deleted.']);
    }

    public function renew(RenewItemRequest $request, int $item): JsonResponse
    {
        $target = $this->findVisible($request->user(), $item);
        Gate::authorize('renew', $target);

        $renewed = $this->items->renew(
            $request->user(),
            $target,
            $request->validated()['due_date'],
            $request->ip(),
        );

        return response()->json(['data' => ItemResource::make($renewed)->resolve($request)]);
    }

    public function status(UpdateItemStatusRequest $request, int $item): JsonResponse
    {
        $target = $this->findVisible($request->user(), $item);
        Gate::authorize('updateStatus', $target);

        $updated = $this->items->changeStatus(
            $request->user(),
            $target,
            $request->validated()['status'],
            $request->ip(),
        );

        return response()->json(['data' => ItemResource::make($updated)->resolve($request)]);
    }

    /**
     * Resolve an item through the visibility scope: items a staff member does not
     * own are a 404, not a 403.
     */
    private function findVisible(User $user, int $item): Item
    {
        return Item::query()
            ->visibleTo($user)
            ->with(Item::EAGER_LOAD)
            ->findOrFail($item);
    }
}
