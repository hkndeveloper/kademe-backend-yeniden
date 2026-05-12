<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

class SystemNotificationController extends Controller
{
    /**
     * Oturum acik kullanicinin okunmamis bildirimlerini listeler.
     * GET /user/notifications
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
     * Bir bildirimi okundu olarak isaretle.
     * PATCH /user/notifications/{id}/read
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
     * Tum bildirimleri okundu yap.
     * POST /user/notifications/read-all
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
     * Bir bildirimi sil.
     * DELETE /user/notifications/{id}
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
