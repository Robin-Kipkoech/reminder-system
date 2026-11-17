<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'department' => [
                'id' => $this->department?->id,
                'name' => $this->department?->name,
            ],
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
            ],
            'creator' => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->name,
            ],
            'assignees' => $this->assignees
                ->map(fn ($assignee): array => ['id' => $assignee->id, 'name' => $assignee->name])
                ->values(),
            'reminder_periods' => $this->reminderPeriods
                ->map(fn ($period): array => [
                    'id' => $period->id,
                    'label' => $period->label,
                    'offset_days' => (int) $period->offset_days,
                ])
                ->values(),
            'due_date' => $this->dueDateString(),
            'status' => $this->status,
            'visibility' => $this->visibility,
            'is_recurring' => (bool) $this->is_recurring,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_interval' => $this->recurrence_interval === null ? null : (int) $this->recurrence_interval,
            'is_overdue' => $this->isOverdue(),
            'days_until_due' => $this->daysUntilDue(),
            'completed_at' => $this->completed_at,
            'completed_by' => $this->completed_by === null ? null : [
                'id' => $this->completedBy?->id,
                'name' => $this->completedBy?->name,
            ],
            'comments_count' => (int) ($this->comments_count ?? 0),
            'can_edit' => $user !== null && Gate::allows('update', $this->resource),
            'can_delete' => $user !== null && Gate::allows('delete', $this->resource),
            'created_at' => $this->created_at,
            'deleted_at' => $this->deleted_at,
        ];
    }

    private function dueDate(): Carbon
    {
        return Carbon::parse($this->due_date)->startOfDay();
    }

    private function dueDateString(): string
    {
        return $this->dueDate()->toDateString();
    }

    private function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->dueDate()->lessThan(Carbon::today());
    }

    private function daysUntilDue(): int
    {
        $diff = Carbon::today()->diffInDays($this->dueDate(), false);

        return (int) $diff;
    }
}
