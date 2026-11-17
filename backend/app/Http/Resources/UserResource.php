<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'must_change_password' => (bool) $this->must_change_password,
            'notify_email' => (bool) $this->notify_email,
            'notify_push' => (bool) $this->notify_push,
            'digest_time' => $this->digest_time === null ? null : substr((string) $this->digest_time, 0, 5),
            'open_tasks_count' => $this->when(isset($this->open_tasks_count), (int) $this->open_tasks_count),
            'overdue_tasks_count' => $this->when(isset($this->overdue_tasks_count), (int) $this->overdue_tasks_count),
            'items_in_charge_count' => $this->when(isset($this->items_in_charge_count), (int) $this->items_in_charge_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
