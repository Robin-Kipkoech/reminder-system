<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Counts of records that would be affected when master data is deactivated or
 * deleted. Tasks and items are not implemented yet, so the tables are queried
 * directly instead of through Eloquent models.
 */
final class RecordUsage
{
    private const OPEN_TASK_STATUSES = ['open', 'in_progress'];

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public static function forClient(int $clientId): array
    {
        return [
            'open_tasks' => DB::table('tasks')
                ->where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->whereIn('status', self::OPEN_TASK_STATUSES)
                ->count(),
            'active_items' => DB::table('items')
                ->where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->count(),
        ];
    }

    /**
     * @return array{open_tasks: int, active_items: int}
     */
    public static function forDepartment(int $departmentId): array
    {
        return [
            'open_tasks' => DB::table('tasks')
                ->where('department_id', $departmentId)
                ->whereNull('deleted_at')
                ->whereIn('status', self::OPEN_TASK_STATUSES)
                ->count(),
            'active_items' => DB::table('item_departments')
                ->join('items', 'items.id', '=', 'item_departments.item_id')
                ->where('item_departments.department_id', $departmentId)
                ->whereNull('items.deleted_at')
                ->where('items.status', 'active')
                ->count(),
        ];
    }
}
