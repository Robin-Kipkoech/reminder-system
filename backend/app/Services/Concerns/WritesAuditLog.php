<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait WritesAuditLog
{
    private function audit(User $actor, string $action, string $entityType, Model|int $entity, ?array $old, ?array $new, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : $entity,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
