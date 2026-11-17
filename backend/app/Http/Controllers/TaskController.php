<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanFilters;
use App\Http\Requests\StoreTaskCommentRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use NormalizesBooleanFilters;

    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanFilters($request, ['mine', 'with_trashed']);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'done', 'overdue'])],
            'client_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'assignee_id' => ['sometimes', 'integer'],
            'mine' => ['sometimes', 'boolean'],
            'due_from' => ['sometimes', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'with_trashed' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(Task::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $paginator = $this->tasks->paginate($filters, $request->user());

        return response()->json([
            'data' => TaskResource::collection($paginator)->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->validated(), $request->ip());

        return response()->json(['data' => TaskResource::make($task)->resolve($request)], 201);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        return response()->json([
            'data' => TaskResource::make($this->findVisible($request->user(), $task))->resolve($request),
        ]);
    }

    public function update(UpdateTaskRequest $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('update', $target);

        $updated = $this->tasks->update($request->user(), $target, $request->validated(), $request->ip());

        return response()->json(['data' => TaskResource::make($updated)->resolve($request)]);
    }

    public function updateDueDate(Request $request, int $task): JsonResponse
    {
        $validated = $request->validate(['due_date' => ['required', 'date_format:Y-m-d']]);
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('update', $target);
        $updated = $this->tasks->updateDueDate($request->user(), $target, $validated['due_date'], $request->ip());

        return response()->json(['data' => TaskResource::make($updated)->resolve($request)]);
    }

    public function destroy(Request $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('delete', $target);

        $this->tasks->delete($request->user(), $target, $request->ip());

        return response()->json(['message' => 'Task deleted.']);
    }

    public function status(UpdateTaskStatusRequest $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('updateStatus', $target);

        return $this->taskResponse(
            $request,
            $this->tasks->changeStatus($request->user(), $target, $request->validated()['status'], $request->ip()),
        );
    }

    public function complete(Request $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('updateStatus', $target);

        return $this->taskResponse($request, $this->tasks->complete($request->user(), $target, $request->ip()));
    }

    public function comments(Request $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('addComment', $target);

        $comments = $target->comments()
            ->with('author')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => TaskCommentResource::collection($comments)->resolve($request),
        ]);
    }

    public function storeComment(StoreTaskCommentRequest $request, int $task): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('addComment', $target);

        $comment = $this->tasks->addComment(
            $request->user(),
            $target,
            $request->validated()['body'],
            $request->ip(),
        );

        return response()->json(['data' => TaskCommentResource::make($comment)->resolve($request)], 201);
    }

    public function destroyComment(Request $request, int $task, int $comment): JsonResponse
    {
        $target = $this->findVisible($request->user(), $task);
        Gate::authorize('addComment', $target);

        /** @var TaskComment $comment */
        $comment = $target->comments()->findOrFail($comment);
        Gate::authorize('deleteComment', [Task::class, $target, $comment]);

        $this->tasks->deleteComment($request->user(), $comment, $request->ip());

        return response()->json(['message' => 'Comment deleted.']);
    }

    /**
     * Resolve a task through the visibility scope: hidden tasks are a 404,
     * not a 403.
     */
    private function findVisible(User $user, int $task): Task
    {
        return Task::query()
            ->visibleTo($user)
            ->with(Task::EAGER_LOAD)
            ->withCount('comments')
            ->findOrFail($task);
    }

    /**
     * @param  array{task: Task, next_task: Task|null}  $result
     */
    private function taskResponse(Request $request, array $result): JsonResponse
    {
        return response()->json([
            'data' => TaskResource::make($result['task'])->resolve($request),
            'meta' => [
                'next_task' => $result['next_task'] === null
                    ? null
                    : TaskResource::make($result['next_task'])->resolve($request),
            ],
        ]);
    }
}
