<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Support\DueDates;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function tasksByPerson(Request $request)
    {
        [$from, $to, $format] = $this->filters($request);
        $aggregate = Task::query()
            ->visibleTo($request->user())
            ->join('task_assignees', 'task_assignees.task_id', '=', 'tasks.id')
            ->groupBy('task_assignees.user_id')
            ->selectRaw(
                'task_assignees.user_id as user_id,
                SUM(CASE WHEN DATE(task_assignees.created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN tasks.status = ? AND DATE(tasks.completed_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN tasks.status = ? AND DATE(tasks.completed_at) BETWEEN ? AND ? AND DATE(tasks.completed_at) <= tasks.due_date THEN 1 ELSE 0 END) as completed_on_time,
                SUM(CASE WHEN tasks.status = ? AND DATE(tasks.completed_at) BETWEEN ? AND ? AND DATE(tasks.completed_at) > tasks.due_date THEN 1 ELSE 0 END) as completed_late,
                SUM(CASE WHEN tasks.status IN (?, ?) AND tasks.due_date < ? THEN 1 ELSE 0 END) as open_overdue',
                [
                    $from, $to,
                    'done', $from, $to,
                    'done', $from, $to,
                    'done', $from, $to,
                    'open', 'in_progress', DueDates::today()->toDateString(),
                ],
            )
            ->get()
            ->keyBy('user_id');
        $rows = User::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'user' => ['id' => $user->id, 'name' => $user->name],
                'assigned' => (int) ($aggregate[$user->id]->assigned ?? 0),
                'completed' => (int) ($aggregate[$user->id]->completed ?? 0),
                'completed_on_time' => (int) ($aggregate[$user->id]->completed_on_time ?? 0),
                'completed_late' => (int) ($aggregate[$user->id]->completed_late ?? 0),
                'open_overdue' => (int) ($aggregate[$user->id]->open_overdue ?? 0),
            ]);

        if ($format === 'csv') {
            return $this->csv($rows, ['user', 'assigned', 'completed', 'completed_on_time', 'completed_late', 'open_overdue'],
                'tasks-by-person.csv', fn (array $row): array => [
                    $row['user']['name'], $row['assigned'], $row['completed'], $row['completed_on_time'],
                    $row['completed_late'], $row['open_overdue'],
                ]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    public function itemsExpiry(Request $request)
    {
        [$from, $to, $format] = $this->filters($request);
        $rows = Item::query()
            ->visibleTo($request->user())
            ->whereBetween('due_date', [$from, $to])
            ->with(['client', 'departments', 'owners'])
            ->orderBy('due_date')
            ->get()
            ->map(fn (Item $item): array => [
                'title' => $item->title,
                'client' => $item->client?->name,
                'departments' => $item->departments->pluck('name')->values()->all(),
                'owners' => $item->owners->pluck('name')->values()->all(),
                'due_date' => DueDates::toDateString($item->due_date),
                'status' => $item->status,
                'days_until_due' => DueDates::daysUntil($item->due_date),
            ]);

        if ($format === 'csv') {
            return $this->csv($rows, ['title', 'client', 'departments', 'owners', 'due_date', 'status', 'days_until_due'],
                'items-expiry.csv', fn (array $row): array => [
                    $row['title'], $row['client'], implode(', ', $row['departments']), implode(', ', $row['owners']),
                    $row['due_date'], $row['status'], $row['days_until_due'],
                ]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    public function tasksByClient(Request $request)
    {
        [$from, $to, $format] = $this->filters($request);
        $visibleTasks = Task::query()->visibleTo($request->user());
        $open = (clone $visibleTasks)
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->pluck('total', 'client_id');
        $overdue = (clone $visibleTasks)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereDate('due_date', '<', DueDates::today()->toDateString())
            ->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->pluck('total', 'client_id');
        $completed = (clone $visibleTasks)
            ->where('status', 'done')
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to)
            ->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->pluck('total', 'client_id');
        $rows = Client::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client): array => [
                'client' => ['id' => $client->id, 'name' => $client->name],
                'open' => (int) ($open[$client->id] ?? 0),
                'overdue' => (int) ($overdue[$client->id] ?? 0),
                'completed' => (int) ($completed[$client->id] ?? 0),
            ]);

        if ($format === 'csv') {
            return $this->csv($rows, ['client', 'open', 'overdue', 'completed'], 'tasks-by-client.csv', fn (array $row): array => [
                $row['client']['name'], $row['open'], $row['overdue'], $row['completed'],
            ]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * @return array{string, string, string}
     */
    private function filters(Request $request): array
    {
        $today = DueDates::today();
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'in:json,csv'],
        ]);
        $from = $filters['from'] ?? $today->copy()->subDays(29)->toDateString();
        $to = $filters['to'] ?? $today->toDateString();

        if (strtotime($to) < strtotime($from)) {
            throw ValidationException::withMessages([
                'to' => ['The to date must be on or after the from date.'],
            ]);
        }

        return [$from, $to, $filters['format'] ?? 'json'];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function csv(Collection $rows, array $headers, string $filename, callable $values): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headers, $values): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, $values($row));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
