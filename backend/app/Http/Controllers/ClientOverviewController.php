<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientResource;
use App\Http\Resources\ItemResource;
use App\Http\Resources\TaskResource;
use App\Models\Client;
use App\Models\Item;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientOverviewController extends Controller
{
    public function __invoke(Request $request, int $client): JsonResponse
    {
        $record = Client::query()->findOrFail($client);
        $tasks = Task::query()->visibleTo($request->user())->where('client_id', $record->id);
        $items = Item::query()->visibleTo($request->user())->where('client_id', $record->id);
        $today = now()->toDateString();
        $openTasks = (clone $tasks)->whereIn('status', ['open', 'in_progress']);
        $activeItems = (clone $items)->where('status', 'active');

        $counts = [
            'open_tasks' => (clone $openTasks)->count(),
            'overdue_tasks' => (clone $openTasks)->whereDate('due_date', '<', $today)->count(),
            'active_items' => (clone $activeItems)->count(),
            'overdue_items' => (clone $activeItems)->whereDate('due_date', '<', $today)->count(),
        ];
        $openTaskRows = (clone $openTasks)
            ->with(Task::EAGER_LOAD)
            ->withCount('comments')
            ->orderBy('due_date')
            ->limit(20)
            ->get();
        $itemRows = (clone $activeItems)
            ->with(Item::EAGER_LOAD)
            ->orderBy('due_date')
            ->limit(20)
            ->get();
        $completedRows = (clone $tasks)
            ->where('status', 'done')
            ->with(Task::EAGER_LOAD)
            ->withCount('comments')
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get();

        return response()->json([
            'client' => ClientResource::make($record)->resolve($request),
            'counts' => $counts,
            'open_tasks' => TaskResource::collection($openTaskRows)->resolve($request),
            'items' => ItemResource::collection($itemRows)->resolve($request),
            'recently_completed' => TaskResource::collection($completedRows)->resolve($request),
        ]);
    }
}
