<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\ItemResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Services\UserService;
use App\Support\DueDates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'staff'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'with_trashed' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['name', 'email', 'role', 'status', 'created_at', 'last_login_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        return UserResource::collection($this->users->paginate($filters));
    }

    public function summary(): JsonResponse
    {
        $roleCounts = User::query()->selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role');
        $statusCounts = User::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'total' => User::query()->count(),
            'by_role' => [
                'admin' => (int) ($roleCounts['admin'] ?? 0),
                'manager' => (int) ($roleCounts['manager'] ?? 0),
                'staff' => (int) ($roleCounts['staff'] ?? 0),
            ],
            'by_status' => [
                'active' => (int) ($statusCounts['active'] ?? 0),
                'suspended' => (int) ($statusCounts['suspended'] ?? 0),
            ],
            'deleted' => User::withTrashed()->whereNotNull('deleted_at')->count(),
        ]);
    }

    public function profile(Request $request, int $user): JsonResponse
    {
        $target = User::withTrashed()->findOrFail($user);
        $today = DueDates::today()->toDateString();
        $openTasksQuery = Task::query()
            ->visibleTo($target)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('assignees', fn ($query) => $query->where('users.id', $target->id));
        $itemsQuery = Item::query()
            ->visibleTo($target)
            ->where('status', 'active')
            ->whereHas('owners', fn ($query) => $query->where('users.id', $target->id));

        $stats = [
            'open_tasks' => (clone $openTasksQuery)->count(),
            'overdue_tasks' => (clone $openTasksQuery)->whereDate('due_date', '<', $today)->count(),
            'completed_last_30_days' => Task::query()
                ->visibleTo($target)
                ->where('status', 'done')
                ->where('completed_by', $target->id)
                ->where('completed_at', '>=', now()->subDays(30))
                ->count(),
            'items_in_charge' => (clone $itemsQuery)->count(),
        ];

        $openTasks = (clone $openTasksQuery)
            ->with(Task::EAGER_LOAD)
            ->withCount('comments')
            ->orderBy('due_date')
            ->limit(20)
            ->get();
        $items = (clone $itemsQuery)
            ->with(Item::EAGER_LOAD)
            ->orderBy('due_date')
            ->limit(20)
            ->get();
        $activity = DB::table('audit_logs')
            ->where('user_id', $target->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'created_at']);
        $loginHistory = DB::table('login_events')
            ->where('user_id', $target->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'email', 'ip_address', 'user_agent', 'successful', 'created_at']);

        return response()->json([
            'user' => UserResource::make($target)->resolve($request),
            'stats' => $stats,
            'open_tasks' => TaskResource::collection($openTasks)->resolve($request),
            'items_in_charge' => ItemResource::collection($items)->resolve($request),
            'recent_activity' => $activity,
            'login_history' => $loginHistory,
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'string', 'max:255']]);

        $users = $this->users->options($validated['search'] ?? null)
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ]);

        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $created = $this->users->create($request->user(), $request->validated(), $request->ip());

        $resource = UserResource::make($created['user']);

        if ($created['temporary_password'] !== null) {
            $resource->additional(['temporary_password' => $created['temporary_password']]);
        }

        return $resource->response()->setStatusCode(201);
    }

    public function show(int $user): UserResource
    {
        return UserResource::make(User::withTrashed()->findOrFail($user));
    }

    public function update(UpdateUserRequest $request, int $user): UserResource
    {
        $target = User::withTrashed()->findOrFail($user);
        $updated = $this->users->update($request->user(), $target, $request->validated(), $request->ip());

        return UserResource::make($updated);
    }

    public function suspend(Request $request, int $user): JsonResponse
    {
        $this->users->suspend($request->user(), User::withTrashed()->findOrFail($user), $request->ip());

        return response()->json(['message' => 'User suspended.']);
    }

    public function activate(Request $request, int $user): JsonResponse
    {
        $this->users->activate($request->user(), User::withTrashed()->findOrFail($user), $request->ip());

        return response()->json(['message' => 'User activated.']);
    }

    public function resetPassword(Request $request, int $user): JsonResponse
    {
        $target = User::withTrashed()->findOrFail($user);
        $temporaryPassword = $this->users->resetPassword($request->user(), $target, $request->ip());

        return response()->json([
            'data' => UserResource::make($target->refresh())->resolve($request),
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $this->users->delete($request->user(), User::withTrashed()->findOrFail($user), $request->ip());

        return response()->json(['message' => 'User deleted.']);
    }

    public function restore(Request $request, int $user): UserResource
    {
        $target = User::onlyTrashed()->findOrFail($user);
        $this->users->restore($request->user(), $target, $request->ip());

        return UserResource::make($target->refresh());
    }
}
