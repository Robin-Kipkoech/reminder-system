<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
            ],
            'departments' => $this->departments
                ->map(fn ($department): array => ['id' => $department->id, 'name' => $department->name])
                ->values(),
            'owners' => $this->owners
                ->map(fn ($owner): array => ['id' => $owner->id, 'name' => $owner->name])
                ->values(),
            'creator' => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->name,
            ],
            'reminder_periods' => $this->reminderPeriods
                ->map(fn ($period): array => [
                    'id' => $period->id,
                    'label' => $period->label,
                    'offset_days' => (int) $period->offset_days,
                ])
                ->values(),
            'due_date' => $this->dueDate()->toDateString(),
            'status' => $this->status,
            'is_recurring' => (bool) $this->is_recurring,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_interval' => $this->recurrence_interval === null ? null : (int) $this->recurrence_interval,
            'is_overdue' => $this->isOverdue(),
            'days_until_due' => $this->daysUntilDue(),
            'suggested_renewal_date' => $this->suggestedRenewalDate()?->toDateString(),
            'last_renewed_at' => $this->last_renewed_at,
            'can_edit' => $user !== null && Gate::allows('update', $this->resource),
            'can_delete' => $user !== null && Gate::allows('delete', $this->resource),
            'can_renew' => $user !== null && Gate::allows('renew', $this->resource),
            'created_at' => $this->created_at,
            'deleted_at' => $this->deleted_at,
        ];
    }

    private function dueDate(): Carbon
    {
        return Carbon::parse($this->due_date)->startOfDay();
    }

    private function isOverdue(): bool
    {
        return $this->status === 'active' && $this->dueDate()->lessThan(Carbon::today());
    }

    private function daysUntilDue(): int
    {
        // diffInDays with a negative flag is signed, so overdue dates come back negative.
        return (int) Carbon::today()->diffInDays($this->dueDate(), false);
    }

    /**
     * One recurrence interval after the due date, rolled forward until it is in
     * the future so the next renewal is always actionable.
     */
    private function suggestedRenewalDate(): ?Carbon
    {
        if (! $this->is_recurring || blank($this->recurrence_type)) {
            return null;
        }

        $interval = max(1, (int) ($this->recurrence_interval ?: 1));
        $today = Carbon::today();

        $advance = match ($this->recurrence_type) {
            'monthly' => fn (Carbon $date): Carbon => $date->addMonthNoOverflow($interval),
            'quarterly' => fn (Carbon $date): Carbon => $date->addMonthNoOverflow(3 * $interval),
            'yearly' => fn (Carbon $date): Carbon => $date->addYearNoOverflow($interval),
            default => fn (Carbon $date): Carbon => $date->addDays($interval),
        };

        $next = $advance($this->dueDate());

        while ($next->lessThan($today)) {
            $next = $advance($next);
        }

        return $next;
    }
}
