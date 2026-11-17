<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:255'],
        ]);
        $term = $filters['q'] ?? '';

        if (mb_strlen($term) < 2) {
            return response()->json(['tasks' => [], 'items' => [], 'clients' => [], 'users' => []]);
        }

        $pattern = Search::pattern($term);
        $user = $request->user();
        $tasks = Task::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw('title LIKE ? ESCAPE ?', [$pattern, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$pattern, '\\']);
            })
            ->with('client')
            ->orderBy('title')
            ->limit(5)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'client_name' => $task->client?->name,
                'due_date' => $task->due_date?->toDateString(),
                'status' => $task->status,
            ]);

        $items = Item::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw('title LIKE ? ESCAPE ?', [$pattern, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$pattern, '\\']);
            })
            ->with('client')
            ->orderBy('title')
            ->limit(5)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'client_name' => $item->client?->name,
                'due_date' => $item->due_date?->toDateString(),
                'status' => $item->status,
            ]);

        $clients = Client::query()
            ->whereRaw('name LIKE ? ESCAPE ?', [$pattern, '\\'])
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name]);

        $users = in_array($user->role, ['admin', 'manager'], true)
            ? User::query()
                ->whereRaw('name LIKE ? ESCAPE ?', [$pattern, '\\'])
                ->orderBy('name')
                ->limit(5)
                ->get(['id', 'name', 'role'])
                ->map(fn (User $result): array => [
                    'id' => $result->id,
                    'name' => $result->name,
                    'role' => $result->role,
                ])
            : collect();

        return response()->json([
            'tasks' => $tasks->values(),
            'items' => $items->values(),
            'clients' => $clients->values(),
            'users' => $users->values(),
        ]);
    }
}
