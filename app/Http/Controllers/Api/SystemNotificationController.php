<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

/**
 * @group User
 */
class SystemNotificationController extends Controller
{
    /**
     * List authenticated user notifications.
     *
     * Requires a valid token and KVKK consent. Returns the latest 30 notifications and unread count for the current user.
     *
     * @group Users
     * @authenticated
     *
     * @response 200 {"notifications":[{"id":1,"type":"request.created","title":"Yeni bildirim","body":"Bildirim metni","is_read":false,"created_at":"2026-06-30T12:00:00+03:00"}],"unread_count":1}
     * @response 401 {"message":"Unauthenticated."}
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = SystemNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->take(30)
            ->get();

        $unreadCount = SystemNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * Mark a notification as read.
     *
     * The notification must belong to the authenticated user.
     *
     * @group Users
     * @authenticated
     *
     * @urlParam id integer required Notification id. Example: 1
     * @response 200 {"message":"Bildirim okundu olarak isaretlendi."}
     * @response 404 {"message":"No query results for model [App\\Models\\SystemNotification]."}
     */
    public function markRead(Request $request, int $id)
    {
        $user = $request->user();
        $notification = SystemNotification::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['message' => 'Bildirim okundu olarak isaretlendi.']);
    }

    /**
     * Mark all notifications as read.
     *
     * Marks every unread notification of the authenticated user as read.
     *
     * @group Users
     * @authenticated
     *
     * @response 200 {"message":"Tum bildirimler okundu olarak isaretlendi."}
     * @response 401 {"message":"Unauthenticated."}
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();

        SystemNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['message' => 'Tum bildirimler okundu olarak isaretlendi.']);
    }

    /**
     * Delete a notification.
     *
     * Deletes only a notification owned by the authenticated user.
     *
     * @group Users
     * @authenticated
     *
     * @urlParam id integer required Notification id. Example: 1
     * @response 200 {"message":"Bildirim silindi."}
     * @response 404 {"message":"No query results for model [App\\Models\\SystemNotification]."}
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $notification = SystemNotification::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->delete();

        return response()->json(['message' => 'Bildirim silindi.']);
    }
}
