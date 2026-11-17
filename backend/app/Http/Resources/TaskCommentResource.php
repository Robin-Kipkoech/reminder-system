<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
            ],
            'can_delete' => $user !== null && $this->canBeDeletedBy($user),
        ];
    }
}
