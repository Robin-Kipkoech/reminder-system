<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Task;
use App\Support\DueDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['sometimes', 'in:tasks,items,all'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'mine' => ['sometimes', 'boolean'],
        ]);
        if (now()->parse($filters['from'])->diffInDays(now()->parse($filters['to'])) > 62) {
            throw ValidationException::withMessages([
                'to' => ['The date range may not exceed 62 days.'],
            ]);
        }

        $user = $request->user();
        $type = $filters['type'] ?? 'all';
        $mine = $filters['mine'] ?? false;
        $events = collect();

        if ($type === 'all' || $type === 'tasks') {
            $tasks = Task::query()
                ->visibleTo($user)
                ->whereBetween('due_date', [$filters['from'], $filters['to']])
                ->when(isset($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
                ->when(isset($filters['department_id']), fn (Builder $query) => $query->where('department_id', $filters['department_id']))
                ->when($mine, fn (Builder $query) => $query->where(function (Builder $mineQuery) use ($user): void {
                    $mineQuery->where('created_by', $user->id)
                        ->orWhereHas('assignees', fn (Builder $assignees) => $assignees->where('users.id', $user->id));
                }))
                ->with('client')
                ->orderBy('due_date')
                ->limit(500)
                ->get();

            $events = $events->concat($tasks->map(fn (Task $task): array => [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => DueDates::toDateString($task->due_date),
                'status' => $task->status,
                'client_name' => $task->client?->name,
                'is_overdue' => $task->status !== 'done' && DueDates::isPast($task->due_date),
            ]));
        }

        if ($type === 'all' || $type === 'items') {
            $items = Item::query()
                ->visibleTo($user)
                ->whereBetween('due_date', [$filters['from'], $filters['to']])
                ->when(isset($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
                ->when(isset($filters['department_id']), fn (Builder $query) => $query->whereHas(
                    'departments',
                    fn (Builder $departments) => $departments->where('departments.id', $filters['department_id']),
                ))
                ->when($mine, fn (Builder $query) => $query->whereHas(
                    'owners',
                    fn (Builder $owners) => $owners->where('users.id', $user->id),
                ))
                ->with('client')
                ->orderBy('due_date')
                ->limit(500)
                ->get();

            $events = $events->concat($items->map(fn (Item $item): array => [
                'type' => 'item',
                'id' => $item->id,
                'title' => $item->title,
                'due_date' => DueDates::toDateString($item->due_date),
                'status' => $item->status,
                'client_name' => $item->client?->name,
                'is_overdue' => $item->status === 'active' && DueDates::isPast($item->due_date),
            ]));
        }

        return response()->json([
            'events' => $events->sortBy('due_date')->take(500)->values(),
        ]);
    }
}
