<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
