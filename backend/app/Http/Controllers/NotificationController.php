<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'unread' => ['sometimes', 'in:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->when(($filters['unread'] ?? null) === '1', fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return UserNotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => UserNotification::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, int $notification): UserNotificationResource
    {
        $record = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($notification);
        $record->forceFill(['read_at' => $record->read_at ?? now()])->save();

        return UserNotificationResource::make($record->refresh());
    }

    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function destroy(Request $request, int $notification): JsonResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($notification)
            ->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
